<?php
declare(strict_types=1);
function packages(bool $activeOnly = false): array { return query('SELECT * FROM packages' . ($activeOnly ? ' WHERE is_active=1' : '') . ' ORDER BY sort_order,id')->fetchAll(); }
function save_package(array $data): void {
    $name = trim((string)($data['name'] ?? '')); $description = trim((string)($data['description'] ?? ''));
    $individual = !empty($data['is_individual']);
    $duration = $individual ? null : filter_var($data['duration_minutes'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>15,'max_range'=>480]]);
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || mb_strlen($description) > 1000 || (!$individual && !$duration)) throw new DomainException('Vyplňte název, popis do 1000 znaků a délku 15–480 minut, nebo individuální domluvu.');
    atomic(function () use ($data,$name,$description,$individual,$duration): void {
        $id = (int)($data['package_id'] ?? 0);
        if ($id) {
            $old = query('SELECT * FROM packages WHERE id=?', [$id])->fetch();
            if (!$old) throw new DomainException('Balíček neexistuje.');
            if (((int)$old['duration_minutes'] !== (int)$duration || (bool)$old['is_individual'] !== $individual) && query('SELECT id FROM slots WHERE package_id=? AND starts_at>?', [$id,date('Y-m-d H:i:s')])->fetch()) throw new DomainException('Balíček má budoucí termíny. Jeho délku ani způsob rezervace nyní nelze změnit. Vytvořte nový balíček.');
            query('UPDATE packages SET name=?,description=?,duration_minutes=?,is_individual=?,is_active=? WHERE id=?', [$name,$description,$duration,(int)$individual,(int)!empty($data['is_active']),$id]);
        } else query('INSERT INTO packages(name,description,duration_minutes,is_individual,is_active,sort_order) VALUES(?,?,?,?,?,10)', [$name,$description,$duration,(int)$individual,(int)!empty($data['is_active'])]);
    });
}
function package_label(array $package): string { return $package['name'] . ' · ' . ($package['is_individual'] ? 'individuálně' : ((int)$package['duration_minutes'] . ' min')); }
