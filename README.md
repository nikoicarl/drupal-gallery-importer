# Drupal Gallery Importer for WordPress

A robust WordPress plugin that imports Drupal galleries from JSON format into a custom post type with background processing, batch imports, and multiple display options.

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](LICENSE)

## 🎯 Features

### Core Functionality
- **JSON Import**: Import galleries from Drupal JSON exports
- **Background Processing**: Automatic background processing for large imports
- **Batch Management**: Process galleries in configurable batches to prevent timeouts
- **Image Downloads**: Automatically download and import images to WordPress media library
- **Progress Tracking**: Real-time progress monitoring with pause/resume/stop controls
- **Smart Duplicate Detection**: Skip existing galleries based on Drupal NID

### Gallery Management
- **Custom Post Type**: Dedicated `gallery` post type with full WordPress support
- **Taxonomy Support**: `gallery_type` taxonomy for organizing galleries
- **Meta Storage**: Preserves Drupal NID and original links
- **Sortable Images**: Drag-and-drop image ordering in admin
- **Media Library Integration**: Full WordPress media library support

### Display Options
- **Auto Display**: Galleries automatically append to single gallery pages
- **Shortcodes**: Multiple shortcode options for flexible display
- **Responsive Grid**: Mobile-friendly responsive layouts
- **Lightbox**: Built-in lightbox for image viewing
- **Customizable Columns**: 1-6 column layouts

### Performance & Safety
- **Memory Management**: Smart memory monitoring and allocation
- **Timeout Protection**: Automatic background processing for large files
- **Optimized Deletion**: Background processing for galleries with 30+ images
- **Orphan Protection**: Only deletes images not used elsewhere
- **Cache Management**: Intelligent cache clearing during processing

## 📋 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher
- PHP extensions: `json`, `curl`, `gd` or `imagick`

## 🚀 Installation

### Manual Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/drupal-gallery-importer/`
3. Activate through WordPress admin → Plugins
4. Navigate to **Galleries → Import** to begin

### Via WordPress Admin

1. Go to Plugins → Add New
2. Upload the plugin ZIP file
3. Click "Install Now" then "Activate"

## 📖 Usage

### Importing Galleries

1. Navigate to **Galleries → Import**
2. Upload your JSON file
3. Configure import options:
   - **Drupal Site URL**: Base URL for image downloads
   - **Skip Existing**: Avoid duplicate imports
   - **Download Images**: Import images to media library
   - **Background Processing**: Recommended for large imports

4. Click "Import Galleries"
5. Monitor progress in real-time

### JSON Format

```json
{
  "items": [
    {
      "nid": 8821,
      "title": "Summer Gallery 2024",
      "description": "Beautiful summer photos",
      "summary": "A collection of summer memories",
      "publish_date": "2024-06-15 10:30:00",
      "link": "/gallery/summer-2024",
      "gallery_types": [
        { "name": "Events" },
        { "name": "Seasonal" }
      ],
      "images": [
        "/sites/default/files/gallery/image1.jpg",
        "/sites/default/files/gallery/image2.jpg"
      ]
    }
  ]
}
```

### Shortcodes

#### Single Gallery Display
```
[gallery_display id="123" columns="4" size="medium" lightbox="yes"]
```

**Parameters:**
- `id` - Gallery post ID (default: current post)
- `columns` - Number of columns 1-6 (default: 3)
- `size` - Image size (default: medium)
- `lightbox` - Enable lightbox yes/no (default: yes)

#### Gallery Index with Pagination
```
[gallery_index_lightbox per_page="24" columns="4" size="large" show_titles="yes" orderby="date" order="DESC"]
```

**Parameters:**
- `per_page` - Galleries per page (default: 24)
- `columns` - Grid columns 1-6 (default: 4)
- `size` - Thumbnail size (default: large)
- `show_titles` - Display titles yes/no (default: yes)
- `orderby` - Sort field (default: date)
- `order` - ASC or DESC (default: DESC)
- `new_tab` - Open in new tab yes/no (default: no)

## 🎨 Customization

### Filters

```php
// Modify gallery columns on single pages
add_filter('dgi_gallery_columns', function($columns) {
    return 5; // Return 1-6
});

// Force asset loading on specific pages
add_filter('dgi_force_assets', function($force) {
    return is_page('galleries');
});
```

### Styling

The plugin includes responsive CSS that can be overridden in your theme:

```css
/* Override gallery grid */
.nu-gallery-grid {
    gap: 20px;
}

/* Customize gallery items */
.nu-gallery-item {
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
```

## ⚙️ Configuration

### Import Settings

Configure in **Galleries → Import**:

- **Batch Size**: Number of galleries per batch (default: 5)
- **Image Batch Size**: Images processed per cycle (default: 3)
- **Max Processing Time**: Seconds per batch (default: 15)
- **Download Timeout**: Image download timeout (default: 45s)

### Deletion Settings

Configure in **Galleries → Settings**:

- **Delete Images**: Remove orphaned images on gallery deletion
- **Delete Terms**: Remove unused gallery type terms
- **Background Threshold**: Galleries with 30+ images process in background

## 🔧 Advanced Features

### Background Processing

Large imports automatically use background processing:
- Files over 3MB
- Insufficient memory detected
- Image downloads enabled

### Job Management

Monitor and control import jobs in **Galleries → Import Jobs**:
- View all import jobs
- Check job status
- Access detailed logs
- View processing history

### Diagnostic Tools

Built-in NID checker helps prevent duplicates:
1. Enter Drupal NID
2. Check if gallery exists
3. View existing gallery if found

### Progress Controls

Real-time job controls during import:
- **Pause**: Temporarily halt processing
- **Resume**: Continue paused jobs
- **Stop**: Cancel import job
- **Live Updates**: Real-time progress tracking

## 🗑️ Gallery Deletion

Smart deletion protects shared resources:

### Images
- Only deletes if not used in other galleries
- Checks featured image usage
- Background processing for 30+ images
- Prevents timeout errors

### Gallery Types
- Only deletes if no other galleries use the term
- Automatic cleanup of orphaned terms
- Configurable via settings

## 🐛 Troubleshooting

### Import Fails

**Issue**: Import stops or fails  
**Solution**: 
- Enable background processing
- Increase PHP `memory_limit` to 512M
- Check PHP error logs
- Verify JSON format

### Images Not Downloading

**Issue**: Images fail to download  
**Solution**:
- Check Drupal site URL is correct
- Verify images are publicly accessible
- Increase `DGI_DOWNLOAD_TIMEOUT` constant
- Check server firewall settings

### Timeout Errors

**Issue**: Page times out during import  
**Solution**:
- Background processing auto-enables for large files
- Reduce `DGI_BATCH_SIZE` constant
- Increase PHP `max_execution_time`
- Use WP-CLI for very large imports

### Duplicate Galleries

**Issue**: Galleries import multiple times  
**Solution**:
- Enable "Skip existing" option
- Use NID checker tool before import
- Check for duplicate NIDs in JSON

## 📊 Performance Tips

### For Large Imports

1. **Enable Background Processing**: Always recommended
2. **Increase PHP Limits**:
   ```ini
   memory_limit = 512M
   max_execution_time = 300
   ```
3. **Use Cron**: Ensure WP-Cron is working
4. **Monitor Logs**: Check import logs for issues
5. **Batch Processing**: Let the plugin handle batching automatically

### For Image-Heavy Galleries

1. **Enable Image Downloads**: Plugin optimizes automatically
2. **Configure Timeouts**: Adjust `DGI_DOWNLOAD_TIMEOUT` if needed
3. **Server Resources**: Ensure adequate bandwidth and storage
4. **Background Jobs**: Large galleries auto-process in background

## 🔌 Constants Reference

Override these in `wp-config.php`:

```php
// Batch processing
define('DGI_BATCH_SIZE', 5);           // Galleries per batch
define('DGI_IMAGE_BATCH_SIZE', 3);     // Images per batch
define('DGI_MAX_SECONDS', 15);         // Max processing time

// File handling
define('DGI_UPLOAD_DIR', 'dgi-imports');
define('DGI_FILE_SIZE_FALLBACK', 3 * 1024 * 1024); // 3MB

// Network
define('DGI_DOWNLOAD_TIMEOUT', 45);    // Image download timeout

// Display
define('DGI_DEFAULT_COLUMNS', 3);      // Default grid columns
```

## 📝 Changelog

### Version 2.5.0
- Added background image deletion for large galleries
- Improved orphan detection for images and terms
- Enhanced deletion settings interface
- Optimized database queries with caching
- Added NID diagnostic tool
- Improved progress tracking and controls

### Version 2.0.0
- Complete background processing system
- Real-time progress monitoring
- Job pause/resume/stop controls
- Batch processing optimization
- Enhanced memory management

### Version 1.0.0
- Initial release
- Basic JSON import functionality
- Gallery custom post type
- Simple shortcode support

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## 📄 License

This plugin is licensed under the GPL v2 or later.

```
Copyright (C) 2024 Carl Nikoi

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

## 👨‍💻 Author

**Carl Nikoi**

## 🙏 Acknowledgments

- WordPress community for best practices
- Drupal migration community for insights
- Contributors and testers

## 📞 Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/drupal-gallery-importer/issues)
- **Documentation**: [Wiki](https://github.com/yourusername/drupal-gallery-importer/wiki)
- **WordPress**: [Support Forum](https://wordpress.org/support/plugin/drupal-gallery-importer)

## 🔮 Roadmap

- [ ] WP-CLI commands for bulk operations
- [ ] REST API endpoints
- [ ] Multi-site support
- [ ] Advanced image optimization
- [ ] Export functionality
- [ ] Gallery templates system
- [ ] Gutenberg blocks

---

**Made with ❤️ for seamless Drupal to WordPress migrations**
