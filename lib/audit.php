<?php
declare(strict_types=1);

/** Catat aktivitas ke audit trail. */
function audit(PDO $pdo, string $action, string $entity = '', ?int $entityId = null, string $description = '', array $meta = []): void
{
    $u = current_user();
    insert_row($pdo, 'audit_logs', [
        'logged_at'   => now_str(),
        'user_id'     => $u['id'] ?? null,
        'user_name'   => $u['name'] ?? 'Sistem',
        'action'      => $action,
        'entity'      => $entity,
        'entity_id'   => $entityId,
        'description' => $description,
        'meta'        => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/** Kode dokumen berurutan: SL-20250101-0007 */
function next_code(PDO $pdo, string $prefix, string $table, string $column = 'code'): string
{
    $stamp = date('Ymd');
    $like = $prefix . '-' . $stamp . '-%';
    $last = (string)qv($pdo, "SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1", [$like], '');
    $seq = $last ? ((int)substr($last, -4)) + 1 : 1;
    return sprintf('%s-%s-%04d', $prefix, $stamp, $seq);
}
