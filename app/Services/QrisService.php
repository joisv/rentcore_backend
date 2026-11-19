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
     * Verify whether a payload's trailing Tag 63 checksum matches CRC-16/CCITT-FALSE.
     */
    public function hasValidCrc(string $payload): bool
    {
        $trimmed = trim($payload);
        if (strlen($trimmed) < 8) {
            return false;
        }
        $pos63 = strlen($trimmed) - 8;
        if (substr($trimmed, $pos63, 4) !== '6304') {
            return false;
        }
        $expectedCrc = strtoupper(substr($trimmed, -4));
        $calculated = $this->calculateCrc16(substr($trimmed, 0, -4));
        return $expectedCrc === $calculated;
    }

    /**
     * Parse top-level EMVCo TLV tags sequentially without descending into nested templates (e.g. 26-51, 62).
     *
     * @return list<array{tag: string, value: string}>|null
     */
    protected function parseRootTlv(string $body): ?array
    {
        $nodes = [];
        $len = strlen($body);
        $offset = 0;

        while ($offset < $len) {
            if ($offset + 4 > $len) {
                return null;
            }
            $tag = substr($body, $offset, 2);
            $lenStr = substr($body, $offset + 2, 2);
            if (!ctype_digit($tag) || !ctype_digit($lenStr)) {
                return null;
            }
            $valLen = (int)$lenStr;
            if ($offset + 4 + $valLen > $len) {
                return null;
            }
            $value = substr($body, $offset + 4, $valLen);
            $nodes[] = ['tag' => $tag, 'value' => $value];
            $offset += 4 + $valLen;
        }

        return $nodes;
    }

    /**
     * Generate Dynamic QRIS payload from a static EMVCo QRIS string using sequential root TLV mutation
     * (never mutating nested substrings inside merchant templates 26-51 or 62), then recalculating CRC-16.
     */
    public function generateDynamicQris(string $rawStaticPayload, int $payableAmount): string
    {
        $payload = trim($rawStaticPayload);
        $amountInt = max(0, (int)$payableAmount);
        $amountStr = (string)$amountInt;

        // 1. Strip trailing CRC Tag 63 ("6304XXXX") if present (after checking CRC structure)
        $body = $payload;
        if (strlen($payload) >= 8 && preg_match('/6304[0-9A-Fa-f]{4}$/', $payload)) {
            // Compute existing CRC for inspection while allowing synthetic CRCs when strictly needed
            $this->hasValidCrc($payload);
            $body = substr($payload, 0, -8);
        } else {
            $pos63 = strrpos($payload, '6304');
            if ($pos63 !== false && $pos63 === strlen($payload) - 4) {
                $body = substr($payload, 0, $pos63);
            }
        }

        // 2. Parse root TLV tags sequentially
        $nodes = $this->parseRootTlv($body);

        if ($nodes !== null && !empty($nodes)) {
            $hasTag01 = false;
            $hasTag54 = false;
            $mutated = [];

            foreach ($nodes as $node) {
                $tag = $node['tag'];
                $val = $node['value'];

                if ($tag === '63') {
                    continue;
                }
                if ($tag === '01') {
                    $hasTag01 = true;
                    $val = '12';
                } elseif ($tag === '54') {
                    $hasTag54 = true;
                    $val = $amountStr;
                }
                $mutated[] = ['tag' => $tag, 'value' => $val];
            }

            if (!$hasTag01) {
                // Insert Tag 01 = '12' right after Tag 00 (or at start)
                $inserted = [];
                $done = false;
                foreach ($mutated as $node) {
                    $inserted[] = $node;
                    if ($node['tag'] === '00' && !$done) {
                        $inserted[] = ['tag' => '01', 'value' => '12'];
                        $done = true;
                    }
                }
                if (!$done) {
                    array_unshift($inserted, ['tag' => '01', 'value' => '12']);
                }
                $mutated = $inserted;
            }

            if (!$hasTag54) {
                // Insert Tag 54 immediately after Tag 53 (Currency Code), or before Tag 58, or at end
                $inserted = [];
                $placed = false;
                foreach ($mutated as $node) {
                    if ($node['tag'] === '58' && !$placed) {
                        $inserted[] = ['tag' => '54', 'value' => $amountStr];
                        $placed = true;
                    }
                    $inserted[] = $node;
                    if ($node['tag'] === '53' && !$placed) {
                        $inserted[] = ['tag' => '54', 'value' => $amountStr];
                        $placed = true;
                    }
                }
                if (!$placed) {
                    $inserted[] = ['tag' => '54', 'value' => $amountStr];
                }
                $mutated = $inserted;
            }

            $serialized = '';
            foreach ($mutated as $node) {
                $v = $node['value'];
                $vLen = str_pad((string)strlen($v), 2, '0', STR_PAD_LEFT);
                $serialized .= $node['tag'] . $vLen . $v;
            }

            $payloadUpTo6304 = $serialized . '6304';
            return $payloadUpTo6304 . $this->calculateCrc16($payloadUpTo6304);
        }

        // Legacy fallback only when input lacks strict root byte-count integrity
        $fallback = $body;
        if (str_starts_with($fallback, '000201010211')) {
            $fallback = '000201010212' . substr($fallback, 12);
        } elseif (!str_starts_with($fallback, '000201010212') && str_starts_with($fallback, '000201')) {
            $fallback = '000201010212' . substr($fallback, 6);
        }
        $length = str_pad((string)strlen($amountStr), 2, '0', STR_PAD_LEFT);
        $tag54 = '54' . $length . $amountStr;
        if (preg_match('/54(\d{2})/', $fallback, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = $matches[0][1];
            $existingLength = (int)$matches[1][0];
            $fallback = substr_replace($fallback, $tag54, $offset, 4 + $existingLength);
        } elseif (($pos = strpos($fallback, '5802ID')) !== false) {
            $fallback = substr($fallback, 0, $pos) . $tag54 . substr($fallback, $pos);
        } else {
            $fallback .= $tag54;
        }
        $payloadUpTo6304 = $fallback . '6304';
        return $payloadUpTo6304 . $this->calculateCrc16($payloadUpTo6304);
    }
}
