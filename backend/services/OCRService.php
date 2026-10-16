<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

function extractTextFromOCRSpace(string $filePath): array
{
    $apiKey = trim((string) env('OCR_SPACE_API_KEY', ''));
    if ($apiKey === '') {
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (is_file($envPath)) {
            $config = parse_ini_file($envPath, false, INI_SCANNER_RAW);
            $apiKey = trim((string) ($config['OCR_SPACE_API_KEY'] ?? ''));
        }
    }

    if ($apiKey === '') {
        return [
            'success' => false,
            'parsed_text' => '',
            'error' => 'Receipt scanning is not configured. Set OCR_SPACE_API_KEY in the server environment.',
        ];
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        return [
            'success' => false,
            'parsed_text' => '',
            'error' => 'The uploaded receipt file could not be read.',
        ];
    }

    if (!extension_loaded('curl')) {
        return [
            'success' => false,
            'parsed_text' => '',
            'error' => 'Receipt scanning is unavailable because the server cURL extension is not enabled.',
        ];
    }

    $curl = curl_init('https://api.ocr.space/parse/image');
    if ($curl === false) {
        return [
            'success' => false,
            'parsed_text' => '',
            'error' => 'Could not start the receipt scanning request.',
        ];
    }

    $file = new CURLFile($filePath);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'apikey' => $apiKey,
            'file' => $file,
            'language' => 'eng',
            'isOverlayRequired' => 'false',
            'scale' => 'true',
            'OCREngine' => '2',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);

    $responseBody = curl_exec($curl);
    $curlErrorNumber = curl_errno($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        $timedOut = $curlErrorNumber === CURLE_OPERATION_TIMEDOUT;
        error_log(sprintf(
            'OCR.space transport failure (curl errno %d).',
            $curlErrorNumber
        ));
        return [
            'success' => false,
            'parsed_text' => '',
            'error' => $timedOut
                ? 'Receipt scanning timed out. Please try again.'
                : 'Could not connect to the receipt scanning service. Please try again.',
        ];
    }

    $response = json_decode($responseBody, true);
    $messages = is_array($response)
        ? ($response['ErrorMessage'] ?? $response['ErrorDetails'] ?? null)
        : null;
    $serviceMessage = is_array($messages)
        ? implode(' ', array_filter($messages, 'is_string'))
        : (is_string($messages) ? $messages : '');
    $normalizedMessage = strtolower($serviceMessage);
    $isLimitError = str_contains($normalizedMessage, 'limit')
        || str_contains($normalizedMessage, 'quota')
        || str_contains($normalizedMessage, 'upgrade');
    $isSizeError = str_contains($normalizedMessage, 'size')
        || str_contains($normalizedMessage, 'large')
        || str_contains($normalizedMessage, 'megabyte');
    $isFormatError = str_contains($normalizedMessage, 'format')
        || str_contains($normalizedMessage, 'unsupported')
        || str_contains($normalizedMessage, 'file type');

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $failureCategory = $isLimitError ? 'quota' : ($isSizeError ? 'image size' : ($isFormatError ? 'image format' : 'request'));
        error_log(sprintf(
            'OCR.space returned HTTP status %d (%s rejection).',
            $httpStatus,
            $failureCategory
        ));

        if ($isLimitError || $httpStatus === 429) {
            $error = 'The receipt scanning service limit has been reached. Please try again later.';
        } elseif (in_array($httpStatus, [401, 403], true)) {
            $error = 'The receipt scanning service rejected its server credentials. Please contact the administrator.';
        } elseif ($isSizeError) {
            $error = 'The receipt image is too large for the scanning service. Please take a closer, lower-resolution photo.';
        } elseif ($isFormatError) {
            $error = 'The receipt scanning service does not support this image format. Please use a JPEG or PNG photo.';
        } elseif ($httpStatus >= 500) {
            $error = 'The receipt scanning service is temporarily unavailable. Please try again later.';
        } else {
            $error = 'The receipt scanning service rejected this request. Please try again with a clear JPEG photo.';
        }

        return [
            'success' => false,
            'parsed_text' => '',
            'error' => $error,
        ];
    }

    if (!is_array($response)) {
        error_log('OCR.space returned an invalid JSON response.');
        return [
            'success' => false,
            'parsed_text' => '',
            'error' => 'The receipt scanning service returned an invalid response.',
        ];
    }

    if (!empty($response['IsErroredOnProcessing'])) {
        error_log(sprintf(
            'OCR.space reported an image processing error (HTTP status %d).',
            $httpStatus
        ));

        if ($isLimitError) {
            $error = 'The receipt scanning service limit has been reached. Please try again later.';
        } elseif ($isSizeError) {
            $error = 'The receipt image is too large for the scanning service. Please take a closer, lower-resolution photo.';
        } elseif ($isFormatError) {
            $error = 'The receipt scanning service does not support this image format. Please use a JPEG or PNG photo.';
        } elseif (in_array($httpStatus, [401, 403], true)) {
            $error = 'The receipt scanning service rejected its server credentials. Please contact the administrator.';
        } else {
            $error = 'The receipt scanning service could not read this file. Please try a clearer image.';
        }

        return [
            'success' => false,
            'parsed_text' => '',
            'error' => $error,
        ];
    }

    $parsedResults = $response['ParsedResults'] ?? [];
    if (!is_array($parsedResults)) {
        return [
            'success' => false,
            'parsed_text' => '',
            'error' => 'The receipt scanning service returned no readable results.',
        ];
    }

    $parsedText = [];
    foreach ($parsedResults as $result) {
        if (isset($result['ParsedText']) && is_string($result['ParsedText'])) {
            $parsedText[] = $result['ParsedText'];
        }
    }
    $text = trim(implode("\n", $parsedText));
    if ($text === '') {
        return [
            'success' => false,
            'parsed_text' => '',
            'error' => 'The receipt scanning service found no readable text. Please try a clearer image.',
        ];
    }

    return [
        'success' => true,
        'parsed_text' => $text,
        'error' => '',
    ];
}
