<?php

return [
    'max_depth' => (int) env('DOCUMENTS_MAX_DEPTH', 10),
    'max_file_size_kb' => (int) env('DOCUMENTS_MAX_FILE_SIZE_KB', 102400),
    'max_media_file_size_kb' => (int) env('DOCUMENTS_MAX_MEDIA_FILE_SIZE_KB', 10240),
    'max_text_content_size_kb' => (int) env('DOCUMENTS_MAX_TEXT_CONTENT_SIZE_KB', 2048),
    'storage_folder' => 'business-documents',
    'per_page' => (int) env('DOCUMENTS_PER_PAGE', 50),
    'folder_page_size' => (int) env('DOCUMENTS_FOLDER_PAGE_SIZE', 100),
];
