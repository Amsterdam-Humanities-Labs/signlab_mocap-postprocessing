# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Motion Capture Post-Processing Management System - A web application for managing animation files from motion capture sessions. The system allows motion capture engineers to download original FBX files, post-process them in Unreal Engine, and upload the processed versions back to the system.

## Database Configuration

- MySQL database: `admin_gebarenoverleg`
- Configuration file: `mysql_config.php`
- Main table: `mocap_files`
  - Required fields: `glos`, `filename`, `datetime`, `is_pp` (0/1), `filename_pp`

## Architecture

### Directory Structure
```
/web/animMIDI/
├── mysql_config.php      # Database configuration
├── public/              # Web-accessible files
│   ├── index.php       # Main entry point
│   ├── css/            # Tailwind CSS build
│   └── js/             # JavaScript files
├── app/                # Application logic
│   ├── models/         # Database models
│   ├── controllers/    # Request handlers
│   └── views/          # HTML templates
├── storage/            # File storage (not used - files stored in /web/gebarenoverleg_media/fbx/)
├── tests/              # PHPUnit tests
└── vendor/             # Composer dependencies
```

### Key Features

1. **File Management**
   - List non-postprocessed animation files
   - Download single FBX files from https://signcollect.nl/gebarenoverleg_media/fbx/
   - Bulk download (10-100 files) as ZIP with original/post_processed folder structure
   - Upload postprocessed files
   - Automatic file recognition by filename

2. **Database Operations**
   - Track postprocessing status with `is_pp` field
   - Store processed filename in `filename_pp`
   - Categorize files by datetime

## Development Commands

```bash
# Install PHP dependencies
composer install

# Run PHPUnit tests
./vendor/bin/phpunit

# Build Tailwind CSS
npx tailwindcss -i ./src/input.css -o ./public/css/style.css --watch

# Start local PHP server
php -S localhost:8000 -t public/
```

## Testing

- Use PHPUnit for unit testing
- Test database operations, file handling, and ZIP creation
- Mock external file downloads for testing

## Important Considerations

- Ensure proper file permissions for /web/gebarenoverleg_media/fbx/post_processed/ directory
- Validate uploaded files match original filenames
- Handle large file uploads (adjust PHP settings if needed)
- Implement proper error handling for network downloads
- Use prepared statements for all database queries