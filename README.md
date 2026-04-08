# Mautic Member Email Uploader

Upload contacts to Mautic from a CSV file using BasicAuth.

## Setup

```bash
# Enter development environment
nix-shell
```

## Configuration

Copy the example env file and add your credentials:

```bash
cp .env.example .env
```

Edit `.env` with your settings:

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
Row 1: Would create/update contact: john.doe@example.com
Row 2: Would create/update contact: jane@example.com

Done. Success: 2, Errors: 0
```

### Upload

Run for real to create/update contacts in Mautic:

```bash
php upload.php --csv contacts.csv
```

Output:
```
Batch rows 1-2: 2 success, 0 errors

Done. Success: 2, Errors: 0
```

### Fetch from Portal

Pull members directly from the member portal API:

```bash
php upload.php --portal --dry-run
php upload.php --portal
```

This requires `PORTAL_TOKEN` in your `.env` file.

The following fields are imported from the portal (preferred_name replaces firstname):
- email
- firstname (or preferred_name if available)
- lastname
- major
- graduation_year
- tshirt_size
- uin
- confirmed_at
- member_since

## CSV Format

| Column | Required | Description |
|--------|----------|-------------|
| email | Yes | Contact email address |
| firstname | No | First name |
| lastname | No | Last name |
| company | No | Company name |
| * | No | Any other Mautic contact field |

## Options

- `--csv <file>` - Path to CSV file (required if not using --portal)
- `--portal` - Fetch members from member portal API instead of CSV
- `--portal-url <url>` - Portal API URL (default: https://portal.ieeetamu.org)
- `--dry-run` - Preview without making changes
- `--help` - Show usage information

## Behavior

- Creates new contacts if email doesn't exist
- Updates existing contacts if email already exists (Mautic auto-merges by email)
- Contacts are uploaded in batches of 100 for fast processing
- Each batch prints a progress line with success/error counts
- Exit code 0 on success, 1 if any errors
