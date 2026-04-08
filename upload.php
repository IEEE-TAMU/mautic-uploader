<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$options = getopt('', ['csv:', 'dry-run', 'help']);
$csvPath = $options['csv'] ?? null;
$dryRun = isset($options['dry-run']);

if (isset($options['help']) || !$csvPath) {
    echo "Usage: php upload.php --csv <file.csv> [--dry-run]\n";
    echo "\nCSV should have 'email' column (required), plus optional columns like firstname, lastname, company\n";
    exit(0);
}

if (!file_exists($csvPath)) {
    fwrite(STDERR, "Error: CSV file not found: $csvPath\n");
    exit(1);
}

$config = [
    'baseUrl' => $_ENV['MAUTIC_BASE_URL'] ?? null,
    'userName' => $_ENV['MAUTIC_USERNAME'] ?? null,
    'password' => $_ENV['MAUTIC_PASSWORD'] ?? null,
];

if (!$config['baseUrl'] || !$config['userName'] || !$config['password']) {
    fwrite(STDERR, "Error: Missing required environment variables. Check .env file.\n");
    exit(1);
}

$httpClient = new Client([
    'timeout' => 30,
]);

$initAuth = new ApiAuth();
$auth = $initAuth->newAuth($config, 'BasicAuth');

$api = new MauticApi();
$contactApi = $api->newApi('contacts', $auth, $config['baseUrl']);

$csv = fopen($csvPath, 'r');
$headers = fgetcsv($csv);
$headers = array_map('strtolower', $headers);

if (!in_array('email', $headers)) {
    fwrite(STDERR, "Error: CSV must have an 'email' column\n");
    exit(1);
}

$emailIdx = array_search('email', $headers);
$rowNum = 1;
$successCount = 0;
$errorCount = 0;

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

    if ($dryRun) {
        echo "Row $rowNum: Would create/update contact: $email\n";
        $successCount++;
        continue;
    }

    try {
        $existing = $contactApi->getList('email:' . $email, 0, 1);
        if (!empty($existing['contacts'])) {
            $existingId = array_keys($existing['contacts'])[0];
            $response = $contactApi->edit($existingId, $data);
        } else {
            $response = $contactApi->create($data);
        }

        if (isset($response['errors'])) {
            echo "Row $rowNum: Error for $email: " . $response['errors'][0]['message'] . "\n";
            $errorCount++;
        } else {
            echo "Row $rowNum: Success - $email\n";
            $successCount++;
        }
    } catch (Exception $e) {
        echo "Row $rowNum: Exception for $email: " . $e->getMessage() . "\n";
        $errorCount++;
    }
}

fclose($csv);

echo "\nDone. Success: $successCount, Errors: $errorCount\n";
exit($errorCount > 0 ? 1 : 0);
