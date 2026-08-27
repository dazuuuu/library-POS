<?php
// app/models/TimeLogModel.php
// Clock in / clock out for staff. Runs before a full session exists (the
// staff terminal only has a PIN so far), so callers pass tenant/user ids
// explicitly rather than relying on TenantContext.
//
// Rule: one clock-in per calendar day, unless the owner authorizes another
// (staff_reclock_authorizations — a one-time slip, consumed on use). A
// clock-in left open from a previous day (forgotten clock-out) is
// auto-closed the next time this person clocks in, flagged `auto_closed`,
// so it never silently reads as "still active" and never blocks the new day.
namespace Models;

class TimeLogModel extends Model
{
    protected string $table = 'staff_time_logs';

    public function __construct(?\PDO $db = null)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    public function settings(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM staff_attendance_settings WHERE tenant_id = ? LIMIT 1');
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        $this->db->prepare('INSERT IGNORE INTO staff_attendance_settings (tenant_id) VALUES (?)')->execute([$tenantId]);
        return [
            'tenant_id' => $tenantId,
            'clock_in_time' => '08:00:00',
            'clock_out_time' => '18:00:00',
            'late_grace_minutes' => 0,
        ];
    }

    public function updateSettings(int $tenantId, string $clockIn, string $clockOut, int $graceMinutes = 0): array
    {
        if (!$this->validTime($clockIn) || !$this->validTime($clockOut)) {
            return ['ok' => false, 'error' => 'Enter valid clock-in and clock-out times.'];
        }
        $graceMinutes = max(0, min(180, $graceMinutes));
        $stmt = $this->db->prepare(
            'INSERT INTO staff_attendance_settings (tenant_id, clock_in_time, clock_out_time, late_grace_minutes)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE clock_in_time = VALUES(clock_in_time), clock_out_time = VALUES(clock_out_time), late_grace_minutes = VALUES(late_grace_minutes)'
        );
        $stmt->execute([$tenantId, $clockIn . ':00', $clockOut . ':00', $graceMinutes]);
        return ['ok' => true, 'error' => null];
    }

    public function clockIn(int $tenantId, int $userId): array
    {
        $this->autoCloseOverdueForTenant($tenantId);
        $today = date('Y-m-d');
        $last = $this->lastLog($tenantId, $userId);

        if ($last && $last['clock_out_at'] === null && $this->dateOf($last['clock_in_at']) !== $today) {
            $this->autoClose($last);
            $last = null; // yesterday's forgotten clock-out doesn't block today
        }

        if ($last && $this->dateOf($last['clock_in_at']) === $today) {
            if (!$this->consumeReclockAuthorization($tenantId, $userId)) {
                return ['ok' => false, 'error' => 'You already clocked in today. Ask the owner to authorize another clock-in.'];
            }
        }

        $settings = $this->settings($tenantId);
        $lateAfter = strtotime($today . ' ' . $settings['clock_in_time']) + ((int) $settings['late_grace_minutes'] * 60);
        $isLate = time() > $lateAfter;

        $stmt = $this->db->prepare(
            'INSERT INTO staff_time_logs (tenant_id, user_id, clock_in_at, late_clock_in) VALUES (?, ?, NOW(), ?)'
        );
        $stmt->execute([$tenantId, $userId, $isLate ? 1 : 0]);
        return ['ok' => true, 'error' => null, 'at' => date('g:i a'), 'late' => $isLate];
    }

    public function clockOut(int $tenantId, int $userId): array
    {
        $this->autoCloseOverdueForTenant($tenantId);
        $open = $this->lastLog($tenantId, $userId);
        if (!$open || $open['clock_out_at'] !== null) {
            return ['ok' => false, 'error' => "You haven't clocked in, or you've already clocked out."];
        }

        $stmt = $this->db->prepare('UPDATE staff_time_logs SET clock_out_at = NOW() WHERE id = ?');
        $stmt->execute([$open['id']]);
        return ['ok' => true, 'error' => null, 'at' => date('g:i a')];
    }

    private function ensureSchema(): void
    {
        try {
            $this->db->query("SELECT 1 FROM `staff_time_logs` LIMIT 1");
        } catch (\PDOException $e) {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `staff_time_logs` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `tenant_id` INT NOT NULL,
                `user_id` INT NOT NULL,
                `clock_in_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `clock_out_at` DATETIME NULL DEFAULT NULL,
                `auto_closed` TINYINT(1) NOT NULL DEFAULT 0,
                `late_clock_in` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_stafflog_tenant_user` (`tenant_id`, `user_id`),
                KEY `idx_stafflog_clockin` (`clock_in_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        try {
            $this->db->query("SELECT 1 FROM `staff_reclock_authorizations` LIMIT 1");
        } catch (\PDOException $e) {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `staff_reclock_authorizations` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `tenant_id` INT NOT NULL,
                `user_id` INT NOT NULL,
                `authorized_by` INT NOT NULL,
                `authorized_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `used_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_reclock_user` (`tenant_id`, `user_id`, `used_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        $this->ensureColumn('staff_time_logs', 'late_clock_in', "ALTER TABLE staff_time_logs ADD COLUMN late_clock_in TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_closed");
        try {
            $this->db->query("SELECT 1 FROM `staff_attendance_settings` LIMIT 1");
        } catch (\PDOException $e) {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `staff_attendance_settings` (
                `tenant_id` INT NOT NULL,
                `clock_in_time` TIME NOT NULL DEFAULT '08:00:00',
                `clock_out_time` TIME NOT NULL DEFAULT '18:00:00',
                `late_grace_minutes` INT NOT NULL DEFAULT 0,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`tenant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    /** Has this staff member clocked in at all today? Gates Login on the PIN terminal. */
    public function hasClockedInToday(int $tenantId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM staff_time_logs WHERE tenant_id = ? AND user_id = ? AND DATE(clock_in_at) = CURDATE() LIMIT 1'
        );
        $stmt->execute([$tenantId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Owner grants one more clock-in today for a staff member who's already used theirs. */
    public function authorizeReclock(int $tenantId, int $userId, int $ownerId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO staff_reclock_authorizations (tenant_id, user_id, authorized_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([$tenantId, $userId, $ownerId]);
    }

    /** Does this staff member have an unused re-clock slip waiting? (for the attendance page) */
    public function hasUnusedAuthorization(int $tenantId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM staff_reclock_authorizations WHERE tenant_id = ? AND user_id = ? AND used_at IS NULL LIMIT 1'
        );
        $stmt->execute([$tenantId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Staff currently clocked in (open, not auto-closed) — the attendance page's "In now" list. */
    public function currentlyIn(int $tenantId): array
    {
        $this->autoCloseOverdueForTenant($tenantId);
        $stmt = $this->db->prepare(
            "SELECT l.*, u.username FROM staff_time_logs l
               JOIN users u ON u.id = l.user_id
              WHERE l.tenant_id = ? AND l.clock_out_at IS NULL
           ORDER BY l.clock_in_at ASC"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    /** Recent clock in/out history for every staff member, newest first. */
    public function recentForTenant(int $tenantId, int $limit = 100): array
    {
        $this->autoCloseOverdueForTenant($tenantId);
        $stmt = $this->db->prepare(
            "SELECT l.*, u.username FROM staff_time_logs l
               JOIN users u ON u.id = l.user_id
              WHERE l.tenant_id = ?
           ORDER BY l.clock_in_at DESC, l.id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    public function todaysEvents(int $tenantId): array
    {
        $this->autoCloseOverdueForTenant($tenantId);
        $stmt = $this->db->prepare(
            "SELECT l.*, u.username FROM staff_time_logs l
               JOIN users u ON u.id = l.user_id
              WHERE l.tenant_id = ? AND DATE(l.clock_in_at) = CURDATE()
           ORDER BY l.clock_in_at DESC, l.id DESC"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    public function autoCloseOverdueForTenant(int $tenantId): int
    {
        $settings = $this->settings($tenantId);
        $cutoff = date('Y-m-d') . ' ' . $settings['clock_out_time'];
        if (time() < strtotime($cutoff)) {
            return 0;
        }
        $stmt = $this->db->prepare(
            'UPDATE staff_time_logs
                SET clock_out_at = CONCAT(DATE(clock_in_at), " ", ?), auto_closed = 1
              WHERE tenant_id = ? AND clock_out_at IS NULL AND CONCAT(DATE(clock_in_at), " ", ?) <= NOW()'
        );
        $stmt->execute([$settings['clock_out_time'], $tenantId, $settings['clock_out_time']]);
        return $stmt->rowCount();
    }

    // ---- internals ----

    private function autoClose(array $log): void
    {
        $settings = $this->settings((int) $log['tenant_id']);
        $closeAt = $this->dateOf($log['clock_in_at']) . ' ' . $settings['clock_out_time'];
        $stmt = $this->db->prepare('UPDATE staff_time_logs SET clock_out_at = ?, auto_closed = 1 WHERE id = ?');
        $stmt->execute([$closeAt, $log['id']]);
    }

    private function ensureColumn(string $table, string $column, string $sql): void
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
            $stmt->execute([$table, $column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $this->db->exec($sql);
            }
        } catch (\PDOException $ignored) {
        }
    }

    private function validTime(string $time): bool
    {
        return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    /** Reuses (consumes) the oldest unused re-clock slip, if any. */
    private function consumeReclockAuthorization(int $tenantId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM staff_reclock_authorizations
              WHERE tenant_id = ? AND user_id = ? AND used_at IS NULL
           ORDER BY authorized_at ASC LIMIT 1'
        );
        $stmt->execute([$tenantId, $userId]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            return false;
        }
        $this->db->prepare('UPDATE staff_reclock_authorizations SET used_at = NOW() WHERE id = ?')->execute([(int) $id]);
        return true;
    }

    private function dateOf(string $datetime): string
    {
        return date('Y-m-d', strtotime($datetime));
    }

    private function lastLog(int $tenantId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM staff_time_logs WHERE tenant_id = ? AND user_id = ? ORDER BY clock_in_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$tenantId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
