<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait HandlesDocumentMemberRoles
{
    /** @return array<int, string> */
    protected function parseMemberRoles(Request $request): array
    {
        $roles = $request->input('member_roles', []);
        if (! is_array($roles)) {
            return [];
        }

        $parsed = [];
        foreach ($roles as $userId => $role) {
            $parsed[(int) $userId] = is_string($role) ? $role : 'viewer';
        }

        return $parsed;
    }
}
