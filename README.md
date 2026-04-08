# Mautic Member Email Uploader

Upload contacts to Mautic from a CSV file using BasicAuth.

## Setup

```bash
# Enter development environment
nix-shell
```

## Configuration

Copy the example env file and add your Mautic credentials:

```bash
cp .env.example .env
```

## Usage

### Prepare your CSV

Create a CSV file with at least an `email` column:

```csv
email,firstname,lastname,company
john.doe@example.com,John,Doe,Example Corp
jane@example.com,Jane,Smith,Acme Inc
```

### Dry Run

Preview what would happen without making changes:

```bash
php upload.php --csv contacts.csv --dry-run
```

Output:
```
Row 2: Would create/update contact: john.doe@example.com
Row 3: Would create/update contact: jane@example.com

Done. Success: 2, Errors: 0
```

### Upload

Run for real to create/update contacts in Mautic:

```bash
php upload.php --csv contacts.csv
```

Output:
```
Row 2: Success - john.doe@example.com
Row 3: Success - jane@example.com

Done. Success: 2, Errors: 0
```

## CSV Format

| Column | Required | Description |
|--------|----------|-------------|
| email | Yes | Contact email address |
| firstname | No | First name |
| lastname | No | Last name |
| company | No | Company name |
| * | No | Any other Mautic contact field |

## Options

- `--csv <file>` - Path to CSV file (required)
- `--dry-run` - Preview without making changes
- `--help` - Show usage information

## Behavior

- Creates new contacts if email doesn't exist
- Updates existing contacts if email already exists
- Reports success/error for each row
- Exit code 0 on success, 1 if any errors
