# Motion Capture Post-Processing Manager

A web application for managing motion capture animation files, allowing engineers to download, post-process, and upload FBX files.

## Features

- **File Listing**: View unprocessed animation files categorized by date
- **Download Options**: 
  - Single file download
  - Bulk download (ZIP with folder structure)
- **Upload Support**:
  - Individual file upload
  - ZIP file upload with automatic file recognition
- **Automatic Tracking**: Files are automatically marked as processed when uploaded

## Installation

1. Clone the repository to your web server
2. Run database migration:
   ```bash
   mysql -u your_user -p your_database < database/schema.sql
   ```
3. Install dependencies:
   ```bash
   composer install
   ```
4. Configure your web server to point to the `public/` directory

## Usage

1. Navigate to the application URL
2. View unprocessed files on the main dashboard
3. Download files for post-processing (single or bulk)
4. Process files in Unreal Engine
5. Upload processed files back to the system
6. Files are automatically marked as processed

## File Structure

- Original files: Downloaded from `https://signcollect.nl/gebarenoverleg_media/fbx/`
- Processed files: Stored in `storage/fbx/post_processed/`

## Requirements

- PHP 7.4+
- MySQL database
- PHP extensions: PDO, cURL, ZIP
- Web server with URL rewriting support