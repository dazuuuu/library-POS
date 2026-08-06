<?php
// app/models/TimeLogModel.php
// Clock in / clock out for staff. Runs before a full session exists (the
// staff terminal only has a PIN so far), so callers pass tenant/user ids
// explicitly rather than relying on TenantContext.
namespace Models;

class TimeLogModel extends Model
{
    protected string $table = 'staff_time_logs';

    /** Can't clock in again within this many hours of the last clock-in. */
    const COOLDOWN_HOURS = 7;

    public function clockIn(int $tenantId, int $userId): array
    {
        $last = $this->lastLog($tenantId, $userId);

        if ($last && $last['clock_out_at'] === null) {
            return ['ok' => false, 'error' => 'You are already clocked in.'];
        }
        if ($last) {
            $hoursSince = (time() - strtotime($last['clock_in_at'])) / 3600;
            if ($hoursSince < self::COOLDOWN_HOURS) {
                $remaining = (int) ceil(self::COOLDOWN_HOURS - $hoursSince);
                return ['ok' => false, 'error' => "You already clocked in recently. Try again in about {$remaining}h."];
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO staff_time_logs (tenant_id, user_id, clock_in_at) VALUES (?, ?, NOW())'
        );
        $stmt->execute([$tenantId, $userId]);
        return ['ok' => true, 'error' => null, 'at' => date('g:i a')];
    }

    public function clockOut(int $tenantId, int $userId): array
    {
        $open = $this->lastLog($tenantId, $userId);
        if (!$open || $open['clock_out_at'] !== null) {
            return ['ok' => false, 'error' => "You haven't clocked in, or you've already clocked out."];
        }

        $stmt = $this->db->prepare('UPDATE staff_time_logs SET clock_out_at = NOW() WHERE id = ?');
        $stmt->execute([$open['id']]);
        return ['ok' => true, 'error' => null, 'at' => date('g:i a')];
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
