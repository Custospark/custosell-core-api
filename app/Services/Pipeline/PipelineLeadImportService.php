<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PipelineLeadImportService
{
    protected const CHUNK_SIZE = 100;

    protected const HEADERS = [
        'Title*',
        'Stage*',
        'Description',
        'Contact Name',
        'Contact Email',
        'Contact Phone',
        'Estimated Value',
        'Due Date',
        'Assignee Email',
        'Priority',
    ];

    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function generateTemplate(PipelineBoard $board): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cards');

        $bold = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $lastCol = chr(65 + count(self::HEADERS) - 1);
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($bold);

        foreach (self::HEADERS as $i => $header) {
            $col = chr(65 + $i);
            $sheet->setCellValue($col.'1', $header);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $firstStage = $board->stages()->orderBy('sort_order')->value('name') ?? 'To Do';
        $example = [
            'Example card',
            $firstStage,
            'Optional notes (delete this example row)',
            '',
            '',
            '',
            '',
            '',
            '',
            'medium',
        ];
        foreach ($example as $i => $val) {
            $sheet->setCellValue(chr(65 + $i).'2', $val);
        }

        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
        ]);
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'E5E7EB']]],
        ]);
        $sheet->freezePane('A2');

        $stages = $board->stages()->orderBy('sort_order')->pluck('name')->all();
        if ($stages !== []) {
            $sheet->setCellValue('A4', 'Available stages: '.implode(', ', $stages));
            $sheet->mergeCells("A4:{$lastCol}4");
            $sheet->getStyle('A4')->getFont()->setItalic(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('6B7280'));
        }

        return $spreadsheet;
    }

    /** @return array{imported: int, errors: list<array{row: int, errors: array<string, list<string>>}>, total_rows: int} */
    public function import(int $businessId, User $user, PipelineBoard $board, string $filePath): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($filePath)->getActiveSheet()->toArray();
        array_shift($rows);

        $stageMap = $board->stages()
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn ($stage) => [mb_strtolower(trim((string) $stage->name)) => (int) $stage->id])
            ->all();

        $rowEntries = [];
        foreach ($rows as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            // Skip helper rows like "Available stages: ..."
            if (isset($row[0]) && is_string($row[0]) && str_starts_with(mb_strtolower(trim($row[0])), 'available stages:')) {
                continue;
            }
            $rowEntries[] = ['index' => $index, 'row' => $row];
        }

        $results = ['imported' => 0, 'errors' => [], 'total_rows' => count($rowEntries)];
        if ($rowEntries === []) {
            return $results;
        }

        $cardType = $board->project_id || $board->workspace === 'estimates' ? 'card' : 'lead';

        /** @var array<int, int> $nextPositionByStage */
        $nextPositionByStage = [];
        foreach (array_unique(array_values($stageMap)) as $stageId) {
            $max = PipelineLead::query()->where('stage_id', $stageId)->max('position');
            $nextPositionByStage[(int) $stageId] = (int) ($max ?? 0);
        }

        /** @var array<string, int> $assigneeCache email => user id */
        $assigneeCache = [];

        foreach (array_chunk($rowEntries, self::CHUNK_SIZE) as $chunk) {
            DB::transaction(function () use (
                $businessId,
                $user,
                $board,
                $chunk,
                $stageMap,
                $cardType,
                &$nextPositionByStage,
                &$assigneeCache,
                &$results,
            ) {
                foreach ($chunk as $entry) {
                    $excelRow = $entry['index'] + 2;
                    $mapped = $this->mapRow($entry['row']);
                    $validator = Validator::make($mapped, [
                        'title' => ['required', 'string', 'max:255'],
                        'stage' => ['required', 'string', 'max:120'],
                        'description' => ['nullable', 'string', 'max:5000'],
                        'contact_name' => ['nullable', 'string', 'max:255'],
                        'contact_email' => ['nullable', 'email', 'max:255'],
                        'contact_phone' => ['nullable', 'string', 'max:50'],
                        'estimated_value' => ['nullable', 'numeric', 'min:0'],
                        'due_date' => ['nullable', 'date'],
                        'assignee_email' => ['nullable', 'email', 'max:255'],
                        'priority' => ['nullable', 'in:low,medium,high,urgent'],
                    ]);

                    if ($validator->fails()) {
                        $results['errors'][] = ['row' => $excelRow, 'errors' => $validator->errors()->toArray()];
                        continue;
                    }

                    $data = $validator->validated();
                    $stageKey = mb_strtolower(trim($data['stage']));
                    if (! isset($stageMap[$stageKey])) {
                        $results['errors'][] = [
                            'row' => $excelRow,
                            'errors' => ['stage' => ['Stage "'.$data['stage'].'" was not found on this board.']],
                        ];
                        continue;
                    }

                    $assigneeIds = [];
                    if (! empty($data['assignee_email'])) {
                        $emailKey = mb_strtolower(trim($data['assignee_email']));
                        if (! array_key_exists($emailKey, $assigneeCache)) {
                            $assignee = User::query()
                                ->where('business_id', $businessId)
                                ->where('email', $data['assignee_email'])
                                ->first();
                            $assigneeCache[$emailKey] = $assignee ? (int) $assignee->id : 0;
                        }
                        if ($assigneeCache[$emailKey] === 0) {
                            $results['errors'][] = [
                                'row' => $excelRow,
                                'errors' => ['assignee_email' => ['No team member found with that email.']],
                            ];
                            continue;
                        }
                        $assigneeIds = [$assigneeCache[$emailKey]];
                    }

                    try {
                        $stageId = $stageMap[$stageKey];
                        $nextPositionByStage[$stageId] = ($nextPositionByStage[$stageId] ?? 0) + 1;
                        $payload = [
                            'board_id' => $board->id,
                            'stage_id' => $stageId,
                            'title' => $data['title'],
                            'card_type' => $cardType,
                            'description' => $data['description'] ?? null,
                            'contact_name' => $data['contact_name'] ?? null,
                            'contact_email' => $data['contact_email'] ?? null,
                            'contact_phone' => $data['contact_phone'] ?? null,
                            'estimated_value' => $data['estimated_value'] ?? null,
                            'due_date' => $data['due_date'] ?? null,
                            'priority' => $data['priority'] ?? null,
                        ];
                        if ($assigneeIds !== []) {
                            $payload['assignee_ids'] = $assigneeIds;
                        }
                        $this->pipelineService->createLead($businessId, $user, $payload, [
                            'for_import' => true,
                            'board' => $board,
                            'position' => $nextPositionByStage[$stageId],
                        ]);
                        $results['imported']++;
                    } catch (\Throwable $e) {
                        $results['errors'][] = [
                            'row' => $excelRow,
                            'errors' => ['title' => [$e->getMessage() ?: 'Could not import this row.']],
                        ];
                    }
                }
            });
        }

        return $results;
    }

    /** @param  array<int, mixed>  $row
     *  @return array<string, mixed>
     */
    protected function mapRow(array $row): array
    {
        return [
            'title' => trim((string) ($row[0] ?? '')),
            'stage' => trim((string) ($row[1] ?? '')),
            'description' => $this->nullableString($row[2] ?? null),
            'contact_name' => $this->nullableString($row[3] ?? null),
            'contact_email' => $this->nullableString($row[4] ?? null),
            'contact_phone' => $this->nullableString($row[5] ?? null),
            'estimated_value' => $this->nullableString($row[6] ?? null),
            'due_date' => $this->nullableString($row[7] ?? null),
            'assignee_email' => $this->nullableString($row[8] ?? null),
            'priority' => $this->nullablePriority($row[9] ?? null),
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function nullablePriority(mixed $value): ?string
    {
        $trimmed = $this->nullableString($value);
        if ($trimmed === null) {
            return null;
        }

        return mb_strtolower($trimmed);
    }

    /** @param  array<int, mixed>  $row */
    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
