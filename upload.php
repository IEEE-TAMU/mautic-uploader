<?php

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;

$options = getopt('v', ['csv:', 'portal', 'portal-url:', 'dry-run', 'help', 'verbose']);
$csvPath = $options['csv'] ?? null;
$usePortal = isset($options['portal']);
$portalUrl = $options['portal-url'] ?? $_ENV['PORTAL_URL'] ?? 'https://portal.ieeetamu.org';
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']) || isset($options['v']);

if (isset($options['help'])) {
    echo "Usage: php upload.php [OPTIONS]\n";
    echo "\nOptions:\n";
    echo "  --csv <file>         CSV file to import (required if not using --portal)\n";
    echo "  --portal             Fetch members from member portal API\n";
    echo "  --portal-url <url>   Portal API URL (default: https://portal.ieeetamu.org)\n";
    echo "  --dry-run            Preview without making changes\n";
    echo "  --verbose, -v        Log full request details (endpoint, headers, body)\n";
    echo "  --help               Show this help\n";
    echo "\nCSV format: email column required, others optional (firstname, lastname, company, etc)\n";
    exit(0);
}

$config = [
    'baseUrl' => $_ENV['MAUTIC_BASE_URL'] ?? null,
    'userName' => $_ENV['MAUTIC_USERNAME'] ?? null,
    'password' => $_ENV['MAUTIC_PASSWORD'] ?? null,
];

if (!$config['baseUrl'] || !$config['userName'] || !$config['password']) {
    fwrite(STDERR, "Error: Missing Mautic config. Check .env file.\n");
    exit(1);
}

$stack = HandlerStack::create();
if ($verbose) {
    $stack->push(function (callable $handler) {
        return function ($request, $options) use ($handler) {
            echo "\n=== GATEWAY REQUEST ===\n";
            echo $request->getMethod() . ' ' . $request->getUri() . "\n";
            foreach ($request->getHeaders() as $name => $values) {
                echo "$name: " . implode(', ', $values) . "\n";
            }
            $body = (string) $request->getBody();
            if ($body) {
                $preview = strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
                echo "\n" . $preview . "\n";
            }
            echo "=======================\n\n";
            return $handler($request, $options);
        };
    });
}
$httpClient = new Client([
    'handler' => $stack,
    'timeout' => 30,
]);

$initAuth = new ApiAuth();
$auth = $initAuth->newAuth($config, 'BasicAuth');

$api = new MauticApi();
$contactApi = $api->newApi('contacts', $auth, $config['baseUrl']);

function uploadBatch($contactApi, $batch, $dryRun, $startRow) {
    global $verbose;
    if ($dryRun) {
        foreach ($batch as $i => $contact) {
            $rowNum = $startRow + $i;
            echo "Row $rowNum: Would create/update contact: " . ($contact['email'] ?? 'unknown') . "\n";
            if (!empty($contact['tags'])) {
                echo "Row $rowNum:   Tags: " . implode(', ', $contact['tags']) . "\n";
            }
        }
        return ['success' => count($batch), 'errors' => 0, 'dryRun' => true];
    }

    try {
        if ($verbose) {
            $mauticUrl = $_ENV['MAUTIC_BASE_URL'] . '/api/contacts/batch/new';
            $authHeader = 'Basic ' . base64_encode($_ENV['MAUTIC_USERNAME'] . ':' . $_ENV['MAUTIC_PASSWORD']);
            $body = json_encode($batch, JSON_UNESCAPED_SLASHES);
            $preview = strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;

            echo "\n=== MAUTIC REQUEST ===\n";
            echo "POST $mauticUrl\n";
            echo "Authorization: $authHeader\n";
            echo "Content-Type: application/json\n";
            echo "Accept: application/json\n";
            echo "\n$preview\n";
            echo "======================\n\n";
        }

        $response = $contactApi->createBatch($batch);

        $successCount = 0;
        $errorCount = 0;

        if (isset($response['errors'])) {
            return ['success' => 0, 'errors' => count($response['errors']), 'details' => $response['errors']];
        }

        if (!empty($response['contacts'])) {
            foreach ($response['contacts'] as $contact) {
                if (isset($contact['errors'])) {
                    $errorCount++;
                } else {
                    $successCount++;
                }
            }
        }

        return ['success' => $successCount, 'errors' => $errorCount];
    } catch (Exception $e) {
        return ['success' => 0, 'errors' => 1, 'details' => [$e->getMessage()]];
    }
}

function processBatch($contactApi, &$contactBatch, $dryRun, $batchStartRow, &$successCount, &$errorCount) {
    if (empty($contactBatch)) {
        return;
    }

    $result = uploadBatch($contactApi, $contactBatch, $dryRun, $batchStartRow);
    $successCount += $result['success'];
    $errorCount += $result['errors'];

    if (!$dryRun) {
        echo "Batch rows $batchStartRow-" . ($batchStartRow + count($contactBatch) - 1) . ": " . $result['success'] . " success, " . $result['errors'] . " errors\n";
    }

    if (!empty($result['details'])) {
        foreach ($result['details'] as $err) {
            $msg = is_array($err) ? ($err['message'] ?? 'Unknown error') : $err;
            echo "Batch error: $msg\n";
        }
    }

    $contactBatch = [];
}

function transformMemberToContact($member) {
    $data = [];
    $data['email'] = $member['email'];

    if (isset($member['info'])) {
        $info = $member['info'];
        if (!empty($info['preferred_name'])) {
            $data['firstname'] = $info['preferred_name'];
        } elseif (!empty($info['first_name'])) {
            $data['firstname'] = $info['first_name'];
        }
        if (!empty($info['last_name'])) $data['lastname'] = $info['last_name'];
        if (!empty($info['major'])) $data['major'] = $info['major'];
        if (!empty($info['graduation_year'])) $data['graduation_year'] = (string)$info['graduation_year'];
        if (!empty($info['tshirt_size'])) $data['tshirt_size'] = $info['tshirt_size'];
        if (!empty($info['uin'])) $data['uin'] = (string)$info['uin'];
    }

    if (!empty($member['confirmed_at'])) $data['confirmed_at'] = $member['confirmed_at'];
    if (!empty($member['inserted_at'])) $data['member_since'] = $member['inserted_at'];

    return $data;
}

$successCount = 0;
$errorCount = 0;

if ($usePortal) {
    $portalToken = $_ENV['PORTAL_TOKEN'] ?? null;
    if (!$portalToken) {
        fwrite(STDERR, "Error: PORTAL_TOKEN not set. Check .env file.\n");
        exit(1);
    }

    echo "Fetching members from portal: $portalUrl\n";

    try {
        $response = $httpClient->request('GET', $portalUrl . '/api/v1/members', [
            'headers' => [
                'Authorization' => 'Bearer ' . $portalToken,
                'Accept' => 'application/json',
            ],
        ]);

        $members = json_decode($response->getBody(), true);

        if (!is_array($members)) {
            fwrite(STDERR, "Error: Invalid response from portal\n");
            exit(1);
        }

        echo "Found " . count($members) . " members\n\n";

        $batchSize = 100;
        $contactBatch = [];
        $batchStartRow = 1;
        $rowNum = 1;

        foreach ($members as $member) {
            $rowNum++;
            $email = $member['email'] ?? '';

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo "Row $rowNum: Skipping invalid email: $email\n";
                $errorCount++;
                continue;
            }

            $data = transformMemberToContact($member);
            $data['tags'] = ['portal-upload'];
            $contactBatch[] = $data;

            if (count($contactBatch) >= $batchSize) {
                processBatch($contactApi, $contactBatch, $dryRun, $batchStartRow, $successCount, $errorCount);
                $batchStartRow = $rowNum + 1;
            }
        }

        processBatch($contactApi, $contactBatch, $dryRun, $batchStartRow, $successCount, $errorCount);
    } catch (Exception $e) {
        fwrite(STDERR, "Error fetching from portal: " . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    if (!$csvPath) {
        fwrite(STDERR, "Error: --csv or --portal required. Use --help for usage.\n");
        exit(1);
    }

    if (!file_exists($csvPath)) {
        fwrite(STDERR, "Error: CSV file not found: $csvPath\n");
        exit(1);
    }

    $csv = fopen($csvPath, 'r');
    $headers = fgetcsv($csv);
    $headers = array_map('strtolower', $headers);

    if (!in_array('email', $headers)) {
        fwrite(STDERR, "Error: CSV must have an 'email' column\n");
        exit(1);
    }

    $emailIdx = array_search('email', $headers);
    $batchSize = 100;
    $contactBatch = [];
    $batchStartRow = 1;
    $rowNum = 1;

    while (($row = fgetcsv($csv)) !== false) {
        $rowNum++;
        $data = [];
        foreach ($headers as $i => $header) {
            if ($header !== 'email' && isset($row[$i]) && $row[$i] !== '') {
                $data[$header] = $row[$i];
            }
        }

        $email = $row[$emailIdx] ?? '';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo "Row $rowNum: Skipping invalid email: $email\n";
            $errorCount++;
            continue;
        }

        $data['email'] = $email;
        $data['tags'] = ['manual-upload'];
        $contactBatch[] = $data;

        if (count($contactBatch) >= $batchSize) {
            processBatch($contactApi, $contactBatch, $dryRun, $batchStartRow, $successCount, $errorCount);
            $batchStartRow = $rowNum + 1;
        }
    }

    processBatch($contactApi, $contactBatch, $dryRun, $batchStartRow, $successCount, $errorCount);

    fclose($csv);
}

echo "\nDone. Success: $successCount, Errors: $errorCount\n";
exit($errorCount > 0 ? 1 : 0);
