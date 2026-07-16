<?php
// CouponService — ported from mysqli to PDO (uses getDb() from config.php)

class CouponService
{
    public static function fetchAvailable(string $offer): ?array
    {
        $db = getDb();
        $st = $db->prepare(
            "SELECT id, Coupon FROM offer_coupons
             WHERE is_redeemed = 0 AND Offer = ?
             ORDER BY id ASC LIMIT 1"
        );
        $st->execute([$offer]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function markUsed(string $coupon, array $data): bool
    {
        $db = getDb();
        $st = $db->prepare(
            "UPDATE offer_coupons
             SET Name=?, Mobile=?, Bill_Amount=?, Outlet=?, Approver=?,
                 Remark=?, is_redeemed=1, IPAddress=?, datestamp=?, timestamp=?,
                 employee_code=?
             WHERE Coupon=?"
        );
        return $st->execute([
            $data['name'], $data['mobile'], $data['bill'],
            $data['outlet'], $data['approver'], $data['remark'],
            $data['ip'], $data['date'], $data['time'],
            $data['employee_code'], $coupon
        ]);
    }

    public static function fetchById(int $id): ?array
    {
        $db = getDb();
        $st = $db->prepare("SELECT * FROM offer_coupons WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function getAvailabilitySummary(): array
    {
        $db = getDb();
        $st = $db->query(
            "SELECT Offer, COUNT(*) AS offer_count
             FROM offer_coupons
             WHERE is_redeemed = 0
             GROUP BY Offer
             ORDER BY CAST(REPLACE(Offer, '% Discount', '') AS UNSIGNED) ASC"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
