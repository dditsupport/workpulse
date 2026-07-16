<?php
// SmsService — sends SMS via MSG91 API, reads config from system_settings

class SmsService
{
    // Append a line to uploads/sms_debug.log so SMS sends can be traced
    // without server access. Authkey is masked. Safe no-op if the dir is
    // unwritable.
    private static function debugLog(string $msg): void
    {
        @file_put_contents(
            __DIR__ . '/../uploads/sms_debug.log',
            date('Y-m-d H:i:s') . ' ' . $msg . "\n",
            FILE_APPEND
        );
    }

    // ── MSG91 v5 Flow API ────────────────────────────────────
    // Templated send via https://control.msg91.com/api/v5/flow/. $templateId is
    // the MSG91 template_id (not the DLT id — that's configured inside the MSG91
    // template). $vars keys must match the ##named## variables in the template,
    // e.g. ['OTP' => '123456'] or ['offer'=>…, 'bill'=>…, …]. Returns the raw
    // response: success JSON contains "type":"success"; failures contain "error"
    // — same heuristic callers already use for the legacy send().
    public static function sendFlow(string $templateId, string $mobile, array $vars = []): string
    {
        $payload = [
            'template_id' => $templateId,
            'short_url'   => '0',
            'recipients'  => [ array_merge(['mobiles' => $mobile], $vars) ],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => getSetting('sms_v5_url', 'https://control.msg91.com/api/v5/flow/'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/JSON',
                'Accept: application/json',
                'authkey: ' . getSetting('sms_auth_key'),
            ],
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch) ? curl_error($ch) : '';
        if ($curlErr !== '') {
            $response = 'SMS error: ' . $curlErr;
        }
        curl_close($ch);
        self::debugLog('FLOW tpl=' . $templateId . ' to=' . $mobile
            . ' vars=' . json_encode(array_keys($vars))
            . ' http=' . $httpCode . ($curlErr !== '' ? ' curlErr=' . $curlErr : '')
            . ' resp=' . substr((string)$response, 0, 300));
        return (string) $response;
    }

    public static function send(string $to, string $message, ?string $dltIdOverride = null): string
    {
        $postData = [
            'authkey'    => getSetting('sms_auth_key'),
            'mobiles'    => $to,
            'message'    => urlencode($message),
            'sender'     => getSetting('sms_sender_id', 'DANGEE'),
            'DLT_TE_ID'  => $dltIdOverride !== null && $dltIdOverride !== '' ? $dltIdOverride : getSetting('sms_dlt_id'),
            'route'      => getSetting('sms_route', '4'),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => getSetting('sms_api_url', 'http://api.msg91.com/api/sendhttp.php'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch) ? curl_error($ch) : '';
        if ($curlErr !== '') {
            $response = 'SMS error: ' . $curlErr;
        }
        curl_close($ch);
        self::debugLog('LEGACY to=' . $to . ' dlt=' . ($postData['DLT_TE_ID'] ?? '')
            . ' http=' . $httpCode . ($curlErr !== '' ? ' curlErr=' . $curlErr : '')
            . ' resp=' . substr((string)$response, 0, 300));
        return (string) $response;
    }

    public static function buildOfferMessage(array $data, string $variant = 'approver'): string
    {
        $code = ($variant === 'employee') ? $data['coupon_id'] : $data['coupon_code'];
        return sprintf(
            'OTP code for %s for Rs.%s at Dangee Dums is %s | Employee Name: %s | Store: %s | Remark: %s |',
            $data['offer'], $data['bill'], $code, $data['name'], $data['store'], $data['remark']
        );
    }

    // v5 Flow variables for the offer template — mirrors buildOfferMessage()'s
    // code selection. Keys must match the ##named## variables in the MSG91
    // offer flow: offer, bill, code, name, store, remark.
    public static function buildOfferVars(array $data, string $variant = 'approver'): array
    {
        $code = ($variant === 'employee') ? ($data['coupon_id'] ?? '') : ($data['coupon_code'] ?? '');
        return [
            'offer'  => (string)($data['offer']  ?? ''),
            'bill'   => (string)($data['bill']   ?? ''),
            'code'   => (string)$code,
            'name'   => (string)($data['name']   ?? ''),
            'store'  => (string)($data['store']  ?? ''),
            'remark' => (string)($data['remark'] ?? ''),
        ];
    }

    /** Get approvers from system_settings JSON */
    public static function getApprovers(): array
    {
        $json = getSetting('Approvers', '[]');
        $list = json_decode($json, true);
        return is_array($list) ? $list : [];
    }

    /** Find approver mobile by name */
    public static function approverMobile(string $approverName): ?string
    {
        foreach (self::getApprovers() as $a) {
            if (($a['name'] ?? '') === $approverName) return $a['mobile'] ?? null;
        }
        return null;
    }

    /** Find approver email by name */
    public static function approverEmail(string $approverName): ?string
    {
        foreach (self::getApprovers() as $a) {
            if (($a['name'] ?? '') === $approverName) return $a['email'] ?? null;
        }
        return null;
    }
}
