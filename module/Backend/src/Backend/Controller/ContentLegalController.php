<?php

namespace Backend\Controller;

use Zend\Mvc\Controller\AbstractActionController;

class ContentLegalController extends AbstractActionController
{
    // ── Tier / Variant helpers ────────────────────────────────────────────────

    private function packageTier(string $planKey, ?string $parentPlanKey = null): string
    {
        $ref = strtolower($parentPlanKey ?? $planKey);
        $key = strtolower($planKey);
        if ($ref === 'red'  || str_starts_with($key, 'red'))   return 'Red';
        if ($ref === 'pink' || str_starts_with($key, 'pink'))  return 'Pink';
        if ($ref === 'black'|| str_starts_with($key, 'black')) return 'Black';
        if ($ref === 'gold' || $key === 'gold' || $key === 'pro-package') return 'Gold';
        if (str_starts_with($key, 'summer')) return 'Summer';
        return 'Special';
    }

    private function packageVariant(string $planKey): string
    {
        $key = strtolower($planKey);
        if (str_contains($key, '_nhs_upfront'))   return 'Upfront NHS';
        if (str_contains($key, '_junior'))         return 'Junior';
        if (str_contains($key, '_nhs'))            return 'NHS';
        if (str_contains($key, '_police'))         return 'Police';
        if (str_contains($key, '_senior'))         return 'Senior';
        if (str_contains($key, '_student'))        return 'Student';
        if (str_contains($key, '_standard_upfront') || (str_contains($key, '_upfront') && !str_contains($key, '_nhs'))) return 'Upfront';
        if (str_contains($key, '_discounted'))     return 'Discounted';
        if ($key === 'concession')                 return 'Concession';
        if ($key === 'coach')                      return 'Coach';
        if (str_contains($key, 'special'))         return 'Special';
        return 'Standard';
    }

    private function getPdo(): \PDO
    {
        $dbAdapter = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
        /** @var \PDO $pdo */
        return $dbAdapter->getDriver()->getConnection()->getResource();
    }

    private function hasColumn(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    public function indexAction()
    {
        $this->authorize('admin.config');

        return [];
    }

    public function packagesAction()
    {
        $this->authorize('admin.config');

        $pdo = $this->getPdo();

        $search     = trim((string)($this->params()->fromQuery('search', '')));
        $tierFilter = strtolower(trim((string)($this->params()->fromQuery('tier', ''))));
        $visibility = trim((string)($this->params()->fromQuery('visibility', '')));
        $active     = trim((string)($this->params()->fromQuery('active', '')));

        // Check optional columns
        $hasUpdatedAt   = $this->hasColumn($pdo, 'ssa_membership_plans', 'updated_at');
        $hasBillingType = $this->hasColumn($pdo, 'ssa_membership_plans', 'billing_type');
        $hasAvailFrom   = $this->hasColumn($pdo, 'ssa_membership_plans', 'available_from');
        $hasAvailUntil  = $this->hasColumn($pdo, 'ssa_membership_plans', 'available_until');

        $extraCols = 'p.created_at';
        if ($hasUpdatedAt)   $extraCols .= ', p.updated_at';
        if ($hasBillingType) $extraCols .= ', p.billing_type';
        if ($hasAvailFrom)   $extraCols .= ', p.available_from';
        if ($hasAvailUntil)  $extraCols .= ', p.available_until';

        $sql = "SELECT p.id, p.plan_key, p.name, p.display_name, p.description,
                       p.monthly_price_pence, p.currency,
                       p.is_active, p.is_public, p.parent_plan_id, p.sort_order,
                       pp.plan_key AS parent_plan_key, $extraCols
                FROM ssa_membership_plans p
                LEFT JOIN ssa_membership_plans pp ON pp.id = p.parent_plan_id
                WHERE 1=1";

        $params = [];

        if ($search !== '') {
            $sql .= ' AND (p.display_name LIKE :search OR p.plan_key LIKE :search OR p.name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($active === '1' || $active === 'active') {
            $sql .= ' AND p.is_active = 1';
        } elseif ($active === '0' || $active === 'inactive') {
            $sql .= ' AND p.is_active = 0';
        }

        if ($visibility === 'public') {
            $sql .= ' AND p.is_public = 1';
        } elseif ($visibility === 'assigned_only') {
            $sql .= ' AND p.is_public = 0';
        }

        $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $packages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Apply tier filter in PHP
        if ($tierFilter !== '') {
            $packages = array_values(array_filter($packages, function (array $row) use ($tierFilter): bool {
                $tier = strtolower($this->packageTier(
                    (string)$row['plan_key'],
                    isset($row['parent_plan_key']) ? (string)$row['parent_plan_key'] : null
                ));
                return $tier === $tierFilter;
            }));
        }

        // Enrich with derived fields
        foreach ($packages as &$pkg) {
            $pkg['tier']    = $this->packageTier((string)$pkg['plan_key'], $pkg['parent_plan_key'] ?? null);
            $pkg['variant'] = $this->packageVariant((string)$pkg['plan_key']);
        }
        unset($pkg);

        return [
            'packages'   => $packages,
            'search'     => $search,
            'filters'    => [
                'tier'       => $tierFilter,
                'visibility' => $visibility,
                'active'     => $active,
            ],
        ];
    }

    public function packageEditAction()
    {
        $sessionUser = $this->authorize('admin.config');
        $pdo = $this->getPdo();

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id <= 0) {
            $this->flashMessenger()->addErrorMessage('Invalid package ID.');
            return $this->redirect()->toRoute('backend/content-legal/packages');
        }

        // Check optional columns
        $hasUpdatedAt   = $this->hasColumn($pdo, 'ssa_membership_plans', 'updated_at');
        $hasBillingType = $this->hasColumn($pdo, 'ssa_membership_plans', 'billing_type');
        $hasAvailFrom   = $this->hasColumn($pdo, 'ssa_membership_plans', 'available_from');
        $hasAvailUntil  = $this->hasColumn($pdo, 'ssa_membership_plans', 'available_until');

        $extraCols = 'p.created_at';
        if ($hasUpdatedAt)   $extraCols .= ', p.updated_at';
        if ($hasBillingType) $extraCols .= ', p.billing_type';
        if ($hasAvailFrom)   $extraCols .= ', p.available_from';
        if ($hasAvailUntil)  $extraCols .= ', p.available_until';

        // Fetch package
        $pkgStmt = $pdo->prepare(
            "SELECT p.id, p.plan_key, p.name, p.display_name, p.description,
                    p.monthly_price_pence, p.currency,
                    p.is_active, p.is_public, p.parent_plan_id, $extraCols,
                    pp.plan_key AS parent_plan_key
             FROM ssa_membership_plans p
             LEFT JOIN ssa_membership_plans pp ON pp.id = p.parent_plan_id
             WHERE p.id = :id LIMIT 1"
        );
        $pkgStmt->execute(['id' => $id]);
        $package = $pkgStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$package) {
            $this->flashMessenger()->addErrorMessage('Package not found.');
            return $this->redirect()->toRoute('backend/content-legal/packages');
        }

        $package['tier']    = $this->packageTier((string)$package['plan_key'], $package['parent_plan_key'] ?? null);
        $package['variant'] = $this->packageVariant((string)$package['plan_key']);

        // Audit history
        $auditHistory = [];
        try {
            $auditTableStmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_membership_package_audit'"
            );
            if ((int)$auditTableStmt->fetchColumn() > 0) {
                $auditStmt = $pdo->prepare(
                    'SELECT a.id, a.admin_uid, a.admin_name_snapshot, a.action,
                            a.changed_fields_json, a.old_values_json, a.new_values_json, a.created_at,
                            u.alias AS admin_alias
                     FROM ssa_membership_package_audit a
                     LEFT JOIN bs_users u ON u.uid = a.admin_uid
                     WHERE a.package_id = :id
                     ORDER BY a.created_at DESC LIMIT 10'
                );
                $auditStmt->execute(['id' => $id]);
                $auditHistory = $auditStmt->fetchAll(\PDO::FETCH_ASSOC);
            }
        } catch (\Throwable $auditEx) {
            // Non-fatal
        }

        // ── POST: save ────────────────────────────────────────────────────────
        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();

            $errors = [];

            $displayName = trim(strip_tags((string)($post['displayName'] ?? '')));
            if ($displayName === '') {
                $errors[] = 'Display name is required.';
            } elseif (mb_strlen($displayName) > 128) {
                $errors[] = 'Display name must be 128 characters or fewer.';
            }

            $description = trim(strip_tags((string)($post['description'] ?? '')));
            if ($description === '') $description = null;
            if ($description !== null && mb_strlen($description) > 2000) {
                $errors[] = 'Description must be 2000 characters or fewer.';
            }

            $priceInput = trim((string)($post['price'] ?? '0'));
            $pricePence = (int)round((float)$priceInput * 100);
            if ($pricePence < 0 || $pricePence > 999900) {
                $errors[] = 'Price must be between £0.00 and £9,999.00.';
            }

            $currency = strtoupper(trim((string)($post['currency'] ?? 'GBP')));
            if (mb_strlen($currency) > 3) $currency = 'GBP';

            $isPublic = isset($post['isPublic']) && (int)$post['isPublic'] === 1;
            $isActive = isset($post['isActive']) && (int)$post['isActive'] === 1;

            $validBillingTypes = ['monthly', 'upfront', 'fixed_term', 'other'];
            $billingType = trim((string)($post['billingType'] ?? 'monthly'));
            if (!in_array($billingType, $validBillingTypes, true)) {
                $errors[] = 'Invalid billing type.';
                $billingType = 'monthly';
            }

            $availableFrom = null;
            if (isset($post['availableFrom']) && trim($post['availableFrom']) !== '') {
                $af = trim((string)$post['availableFrom']);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $af)) {
                    $availableFrom = $af;
                } else {
                    $errors[] = 'Available From must be a valid date (YYYY-MM-DD).';
                }
            }

            $availableUntil = null;
            if (isset($post['availableUntil']) && trim($post['availableUntil']) !== '') {
                $au = trim((string)$post['availableUntil']);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $au)) {
                    $availableUntil = $au;
                } else {
                    $errors[] = 'Available Until must be a valid date (YYYY-MM-DD).';
                }
            }

            // Optimistic concurrency
            $submittedUpdatedAt = trim((string)($post['updated_at_check'] ?? ''));
            if ($hasUpdatedAt && $submittedUpdatedAt !== '') {
                $dbUpdatedAt = (string)($package['updated_at'] ?? '');
                if ($dbUpdatedAt !== $submittedUpdatedAt) {
                    $errors[] = 'This package was modified by someone else while you were editing. Please reload and try again.';
                }
            }

            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->flashMessenger()->addErrorMessage($err);
                }
                // Re-render form with current data merged
                return [
                    'package'      => $package,
                    'auditHistory' => $auditHistory,
                    'formErrors'   => $errors,
                ];
            }

            // ── Build UPDATE ──────────────────────────────────────────────────
            $setClauses = [
                'display_name        = :displayName',
                'description         = :description',
                'monthly_price_pence = :pricePence',
                'currency            = :currency',
                'is_public           = :isPublic',
                'is_active           = :isActive',
            ];
            $updateParams = [
                'displayName' => $displayName,
                'description' => $description,
                'pricePence'  => $pricePence,
                'currency'    => $currency,
                'isPublic'    => $isPublic ? 1 : 0,
                'isActive'    => $isActive ? 1 : 0,
                'id'          => $id,
            ];

            if ($hasBillingType) {
                $setClauses[] = 'billing_type = :billingType';
                $updateParams['billingType'] = $billingType;
            }
            if ($hasAvailFrom) {
                $setClauses[] = 'available_from = :availableFrom';
                $updateParams['availableFrom'] = $availableFrom;
            }
            if ($hasAvailUntil) {
                $setClauses[] = 'available_until = :availableUntil';
                $updateParams['availableUntil'] = $availableUntil;
            }
            if ($hasUpdatedAt) {
                $setClauses[] = 'updated_at = UTC_TIMESTAMP()';
            }

            try {
                $pdo->beginTransaction();

                $pdo->prepare(
                    'UPDATE ssa_membership_plans SET ' . implode(', ', $setClauses) . ' WHERE id = :id'
                )->execute($updateParams);

                // Audit
                $editableDbFields = [
                    'displayName' => ['display_name', $displayName],
                    'description' => ['description', $description],
                    'pricePence'  => ['monthly_price_pence', $pricePence],
                    'currency'    => ['currency', $currency],
                    'isPublic'    => ['is_public', $isPublic ? 1 : 0],
                    'isActive'    => ['is_active', $isActive ? 1 : 0],
                ];
                if ($hasBillingType) {
                    $editableDbFields['billingType'] = ['billing_type', $billingType];
                }
                if ($hasAvailFrom) {
                    $editableDbFields['availableFrom'] = ['available_from', $availableFrom];
                }
                if ($hasAvailUntil) {
                    $editableDbFields['availableUntil'] = ['available_until', $availableUntil];
                }

                $changedFields = [];
                $oldValues     = [];
                $newValues     = [];
                foreach ($editableDbFields as $apiKey => [$dbCol, $newVal]) {
                    $oldVal  = $package[$dbCol] ?? null;
                    $oldNorm = is_numeric($oldVal) ? (string)(int)$oldVal : (string)($oldVal ?? '');
                    $newNorm = is_numeric($newVal) ? (string)(int)$newVal : (string)($newVal ?? '');
                    if ($oldNorm !== $newNorm) {
                        $changedFields[] = $apiKey;
                        $oldValues[$apiKey] = $oldVal;
                        $newValues[$apiKey] = $newVal;
                    }
                }

                $auditTableStmt2 = $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ssa_membership_package_audit'"
                );
                if ((int)$auditTableStmt2->fetchColumn() > 0) {
                    $adminName = method_exists($sessionUser, 'getAlias')
                        ? (string)$sessionUser->getAlias()
                        : '';
                    $adminUid  = method_exists($sessionUser, 'getUid')
                        ? (int)$sessionUser->getUid()
                        : 0;

                    $pdo->prepare(
                        'INSERT INTO ssa_membership_package_audit
                            (package_id, admin_uid, admin_name_snapshot, action,
                             changed_fields_json, old_values_json, new_values_json, ip_address)
                         VALUES
                            (:packageId, :adminUid, :adminName, :action,
                             :changedFields, :oldValues, :newValues, :ip)'
                    )->execute([
                        'packageId'     => $id,
                        'adminUid'      => $adminUid,
                        'adminName'     => $adminName !== '' ? $adminName : null,
                        'action'        => 'update',
                        'changedFields' => json_encode($changedFields, JSON_UNESCAPED_SLASHES),
                        'oldValues'     => json_encode($oldValues, JSON_UNESCAPED_SLASHES),
                        'newValues'     => json_encode($newValues, JSON_UNESCAPED_SLASHES),
                        'ip'            => $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);
                }

                $pdo->commit();

                $this->flashMessenger()->addSuccessMessage('Package updated successfully.');
                return $this->redirect()->toRoute('backend/content-legal/packages/edit', ['id' => $id]);

            } catch (\Throwable $saveEx) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $this->flashMessenger()->addErrorMessage('Failed to save: ' . $saveEx->getMessage());
            }
        }

        return [
            'package'      => $package,
            'auditHistory' => $auditHistory,
            'formErrors'   => [],
        ];
    }
}
