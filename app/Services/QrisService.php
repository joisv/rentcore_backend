<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Zxing\QrReader;

class QrisService
{
    /**
     * Decode QR code from an image file path.
     */
    public function decodeQrisImage(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        try {
            // Ensure helper functions are available
            if (!function_exists('arraycopy')) {
                $helper = base_path('vendor/khanamiryan/qrcode-detector-decoder/lib/Common/customFunctions.php');
                if (file_exists($helper)) {
                    require_once $helper;
                }
            }

            $reader = new QrReader($filePath);
            $text = $reader->text();

            if ($text === false || $text === null || trim((string)$text) === '') {
                return null;
            }

            return trim((string)$text);
        } catch (Exception $e) {
            Log::warning('Failed to decode QR image: ' . $e->getMessage(), [
                'file' => $filePath,
            ]);
            return null;
        }
    }

    /**
     * Validate that the decoded text adheres to EMVCo QRIS standards:
     * starts with '000201' and contains '5802ID'.
     */
    public function isValidQrisPayload(?string $payload): bool
    {
        if (empty($payload)) {
            return false;
        }

        $trimmed = trim($payload);
        return str_starts_with($trimmed, '000201') && str_contains($trimmed, '5802ID');
    }

    /**
     * Calculate CRC-16/CCITT-FALSE checksum for EMVCo payloads.
     * Polynomial: 0x1021, Initial: 0xFFFF, No reflection, XOR Out: 0x0000.
     */
    public function calculateCrc16(string $data): string
    {
        $crc = 0xFFFF;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $crc ^= (ord($data[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Generate Dynamic QRIS payload from a raw static EMVCo QRIS string
     * by injecting the dynamic transaction amount and recalculating CRC-16.
     *
     * @param string $rawStaticPayload The static QRIS string
     * @param int $payableAmount The whole integer payable amount (e.g. 50000)
     * @return string The mutated dynamic QRIS string
     */
    public function generateDynamicQris(string $rawStaticPayload, int $payableAmount): string
    {
        $payload = trim($rawStaticPayload);

        // 1. Convert Initiation Method (Tag 01):
        // Replace '010211' (Static initiation) with '010212' (Dynamic initiation).
        if (str_contains($payload, '010211')) {
            $payload = str_replace('010211', '010212', $payload);
        } elseif (!str_contains($payload, '010212') && str_starts_with($payload, '000201')) {
            // If Tag 01 is missing, insert 010212 right after Tag 00 (000201)
            $payload = '000201010212' . substr($payload, 6);
        }

        // 2. Inject Dynamic Bill Amount (Tag 54):
        // Format $payableAmount into a whole integer string without punctuation
        $amountInt = max(0, (int)$payableAmount);
        $amountStr = (string)$amountInt;
        $length = str_pad((string)strlen($amountStr), 2, '0', STR_PAD_LEFT);
        $tag54 = '54' . $length . $amountStr;

        // Check if Tag 54 already exists
        if (preg_match('/54(\d{2})/', $payload, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = $matches[0][1];
            $existingLength = (int)$matches[1][0];
            $totalTagLen = 4 + $existingLength; // '54' (2) + len (2) + val (len)
            $payload = substr_replace($payload, $tag54, $offset, $totalTagLen);
        } else {
            // Insert Tag 54 immediately before Tag 5802ID (Country Code) or Tag 5303360 (Currency Code)
            if (str_contains($payload, '5802ID')) {
                $pos = strpos($payload, '5802ID');
                $payload = substr($payload, 0, $pos) . $tag54 . substr($payload, $pos);
            } elseif (str_contains($payload, '5303360')) {
                $pos = strpos($payload, '5303360');
                $payload = substr($payload, 0, $pos) . $tag54 . substr($payload, $pos);
            } else {
                // Fallback: insert before Tag 6304 if neither anchor exists
                $pos63 = strrpos($payload, '6304');
                if ($pos63 !== false) {
                    $payload = substr($payload, 0, $pos63) . $tag54 . substr($payload, $pos63);
                } else {
                    $payload .= $tag54;
                }
            }
        }

        // 3. Recalculate Checksum (Tag 63):
        // Strip the old 4-character checksum following '6304' at the end of the payload.
        // Extract the entire substring up to and including '...6304'.
        $pos63 = strrpos($payload, '6304');
        if ($pos63 !== false) {
            $payloadUpTo6304 = substr($payload, 0, $pos63 + 4);
        } else {
            $payloadUpTo6304 = $payload . '6304';
        }

        $checksum = $this->calculateCrc16($payloadUpTo6304);

        return $payloadUpTo6304 . $checksum;
    }
}
