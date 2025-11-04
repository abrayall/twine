# Twine - WordPress Link Collection Plugin

## Project Overview

Twine is a WordPress plugin that creates beautiful link collection pages for websites. Perfect for social media bio pages, landing pages, and mobile-friendly link directories.

### Key Features
- **Profile Management**: Custom icons, names, and descriptions
- **Link Management**: Drag-and-drop reordering with touch support
- **Social Media Integration**: 12 pre-configured social media icons
- **Theme System**: 25+ built-in themes plus custom theme support
- **Theme Editor**: Built-in CSS editor with syntax highlighting
- **Mobile-First Design**: Fully responsive with touch-optimized controls
- **Public Endpoint**: Clean `/twine` URL for sharing

### Repository
- **GitHub**: https://github.com/abrayall/twine
- **Local Path**: `/Users/abrayall/Workspace/twine`
- **Branch**: `main`
- **Latest Tag**: `v0.1.0`

## Local Development Environment

### WordPress Docker Setup

**Site URL**: `http://localhost:8082`
**Admin Panel**: `http://localhost:8082/wp-admin`
**phpMyAdmin**: `http://localhost:8081`

### Docker Containers
- **WordPress**: `watchtower_agent_site` (port 8082)
- **Database**: `watchtower_agent_db` (MySQL 8.0)
- **phpMyAdmin**: `watchtower_phpmyadmin` (port 8081)

### WordPress Credentials
- **Username**: `admin`
- **Password**: Available in Docker setup

### Docker Commands
```bash
# Deploy plugin to container
docker cp /Users/abrayall/Workspace/twine/twine.php watchtower_agent_site:/var/www/html/wp-content/plugins/twine/twine.php
docker cp /Users/abrayall/Workspace/twine/assets watchtower_agent_site:/var/www/html/wp-content/plugins/twine/
docker cp /Users/abrayall/Workspace/twine/themes watchtower_agent_site:/var/www/html/wp-content/plugins/twine/
docker cp /Users/abrayall/Workspace/twine/version.properties watchtower_agent_site:/var/www/html/wp-content/plugins/twine/version.properties

# View WordPress logs
docker logs -f watchtower_agent_site

# Access WordPress container shell
docker exec -it watchtower_agent_site bash
```

## Build System

### Version Management

Versions are managed using **git tags** in the format `v{major}.{minor}.{maintenance}`.

**Version Format:**
- `0.1.0` - Clean release at tag `v0.1.0`
- `0.1.0-5` - 5 commits after tag
- `0.1.0-5-11041430` - 5 commits after tag + local changes (timestamp: Nov 4, 14:30)

### Build Scripts

**Unix/Linux/Mac:**
```bash
./build.sh
```

**Windows:**
```cmd
build.bat
```

### How Versioning Works

1. Reads latest git tag matching `v*.*.*` format
2. If commits exist after tag, appends commit count
3. If uncommitted local changes exist, appends timestamp `MMDDHHMM`
4. Generates `version.properties` during build process
5. Updates plugin header version in `twine.php`
6. Creates production-ready ZIP file

**Output:**
- `build/twine-{version}.zip` - WordPress-ready plugin package
- `build/version.properties` - Version metadata file

### Current Version
- **Latest Tag**: `v0.1.0`
- **Development Version**: `0.1.0-{timestamp}` (if uncommitted changes)

### Version Reading

The plugin reads version at runtime from `version.properties`:

```php
function twine_get_version() {
    $version_file = plugin_dir_path(__FILE__) . 'version.properties';

    if (!file_exists($version_file)) {
        return '0.1.0'; // Fallback version
    }

    $properties = parse_ini_file($version_file);

    if ($properties === false || !isset($properties['major']) || !isset($properties['minor']) || !isset($properties['maintenance'])) {
        return '0.1.0'; // Fallback version
    }

    return $properties['major'] . '.' . $properties['minor'] . '.' . $properties['maintenance'];
}

define('TWINE_VERSION', twine_get_version());
```

## Plugin Architecture

### Directory Structure

```
twine/
├── twine.php                           # Main plugin file
├── version.properties                   # Version info (auto-generated on build, committed for dev)
├── build.sh                            # Unix build script
├── build.bat                           # Windows build script
├── README.md                           # User documentation
├── CLAUDE.md                           # Developer documentation (this file)
├── .gitignore                          # Git ignore rules
├── assets/
│   ├── admin.css                       # Admin interface styles
│   ├── admin.js                        # Admin interface JavaScript
│   ├── frontend.css                    # Public page styles
│   ├── theme-editor.js                 # Theme editor JavaScript
│   └── jquery.ui.touch-punch.min.js   # Touch support for drag-and-drop
└── themes/                             # Built-in theme files (25+ themes)
    ├── minimal-white.css               # Default theme
    ├── midnight-dark.css
    ├── ocean-breeze.css
    └── ... (22 more themes)
```

### Main Plugin Class

The `Twine` class handles all plugin functionality:
- Settings management (profile, links, social media, theme)
- Admin interface rendering (tabbed navigation)
- Theme management (built-in and custom themes)
- Rewrite rules for `/twine` endpoint
- Preview system for testing themes

### Data Storage

**Settings File**: `wp-content/twine/settings.json`
```json
{
  "icon": "https://...",
  "name": "Your Name",
  "description": "Your description",
  "links": [
    {"text": "My Website", "url": "https://example.com"}
  ],
  "social": {
    "facebook": "",
    "twitter": "",
    "instagram": ""
  },
  "theme": "minimal-white"
}
```

**Custom Themes**: `wp-content/uploads/twine/themes/`
- User-created themes stored here
- Theme files must have proper CSS header with metadata

### Admin Interface

Uses tabbed navigation system:
- **Profile Tab**: Icon upload, name, description
- **Links Tab**: Add/edit/reorder/delete links with drag-and-drop
- **Social Tab**: Social media URL inputs (12 platforms)
- **Themes Tab**: Theme selection (redirects to full Themes page)

### Public Endpoint

The `/twine` URL is registered via WordPress rewrite rules:
- Displays the public link collection page
- Always shows "live" data (not preview mode)
- Applies selected theme CSS
- Mobile-optimized responsive layout

## Development Guidelines

### Git Commit Messages

**IMPORTANT RULES:**

1. **Do NOT commit automatically** - always ask first
2. **Keep commits to single-line summaries** - no multi-line bullet lists
3. **DO NOT INCLUDE "CLAUDE" OR "CLAUDE CODE" IN COMMIT MESSAGES** ⚠️
4. Use present tense, imperative mood

**Good commit message examples:**
- `Add drag-and-drop support for mobile devices`
- `Update plugin author to Brayall, LLC`
- `Fix theme preview not loading on Safari`
- `Remove colons from link field labels`
- `Add version reading from version.properties`

**Bad commit message examples:**
- ❌ `Update plugin with Claude Code` (mentions Claude)
- ❌ `Add new features:\n- Drag and drop\n- Mobile support` (multi-line)
- ❌ `Added theme support` (past tense)
- ❌ `feat: add mobile support` (conventional commits not used)

### Code Standards

- **No inline comments** - keep code clean and self-documenting
- **Use WordPress coding standards** for PHP
- **Mobile-first responsive design** - always test on mobile
- **Touch-enabled interactions** - use jQuery UI Touch Punch for sortable
- **Clean URLs** - use WordPress rewrite API
- **Version from properties file** - never hardcode version numbers

### Files in .gitignore

- `/build/*.zip` - Built plugin packages
- `.DS_Store` - macOS system files
- `*.swp`, `*.swo`, `*~` - Editor temporary files
- `.vscode/`, `.idea/` - IDE configuration

## Testing

### Manual Testing Checklist

**Profile Management:**
- [ ] Upload icon via Media Library
- [ ] Enter name and description
- [ ] Save and verify display on `/twine`

**Link Management:**
- [ ] Add new links
- [ ] Edit existing links
- [ ] Drag-and-drop reorder (desktop)
- [ ] Touch drag-and-drop (mobile)
- [ ] Delete links
- [ ] Verify order on `/twine`

**Theme System:**
- [ ] Browse theme gallery
- [ ] Click theme to preview in new tab
- [ ] Activate theme with "Use" button
- [ ] Verify active theme has blue border
- [ ] Create custom theme in editor
- [ ] Upload custom theme CSS file
- [ ] Delete custom theme

**Social Media:**
- [ ] Add social media URLs
- [ ] Verify icons display on `/twine`
- [ ] Test icon links open in new tab
- [ ] Leave some URLs blank (should not display)

**Mobile/Responsive:**
- [ ] Test on iOS Safari
- [ ] Test on Chrome Mobile
- [ ] Test on Android browser
- [ ] Verify touch drag-and-drop works
- [ ] Verify responsive layout adjustments

### Testing URLs

```bash
# Check WordPress is running
curl -s -o /dev/null -w "%{http_code}" http://localhost:8082
# Should return: 200

# View Twine public page
open http://localhost:8082/twine

# View admin settings
open http://localhost:8082/wp-admin/admin.php?page=twine

# View theme gallery
open http://localhost:8082/wp-admin/admin.php?page=twine-theme-editor
```

## Deployment Workflow

### Development Cycle

1. Make code changes in `/Users/abrayall/Workspace/twine/`
2. Test locally by deploying to Docker:
   ```bash
   docker cp /Users/abrayall/Workspace/twine/twine.php watchtower_agent_site:/var/www/html/wp-content/plugins/twine/twine.php
   docker cp /Users/abrayall/Workspace/twine/assets watchtower_agent_site:/var/www/html/wp-content/plugins/twine/
   ```
3. Test in browser: http://localhost:8082/twine
4. Commit changes (following commit message rules above)
5. Push to GitHub

### Release Workflow

1. Ensure all changes are committed
2. Create version tag:
   ```bash
   git tag v0.2.0
   git push --tags
   ```
3. Build release package:
   ```bash
   ./build.sh
   ```
4. Upload `build/twine-0.2.0.zip` to production WordPress sites
5. Activate via WordPress admin: **Plugins → Add New → Upload Plugin**

## Important Files

### Core Plugin Files
- `twine.php` - Main plugin file (line 39: TWINE_VERSION constant)
- `version.properties` - Version metadata (read at runtime)

### Admin Assets
- `assets/admin.css` - Admin interface styling (responsive, tabs)
- `assets/admin.js` - Admin interface JavaScript (drag-and-drop, AJAX, tabs)
- `assets/theme-editor.js` - Theme editor with syntax highlighting

### Frontend Assets
- `assets/frontend.css` - Public page base styles
- `themes/*.css` - 25+ theme files with metadata headers

### Build System
- `build.sh` - Unix build script (git version parsing)
- `build.bat` - Windows build script (PowerShell-based)
- `.gitignore` - Excludes build artifacts, IDE files

## Key Constants

```php
define('TWINE_VERSION', twine_get_version());              // Read from version.properties
define('TWINE_PLUGIN_DIR', plugin_dir_path(__FILE__));     // Plugin directory path
define('TWINE_PLUGIN_URL', plugin_dir_url(__FILE__));      // Plugin URL
define('TWINE_DATA_DIR', WP_CONTENT_DIR . '/twine');       // Data storage directory
define('TWINE_LINKS_FILE', TWINE_DATA_DIR . '/settings.json'); // Settings file
define('TWINE_CUSTOM_THEMES_DIR', WP_CONTENT_DIR . '/uploads/twine/themes'); // Custom themes directory
define('TWINE_CUSTOM_THEMES_URL', content_url('/uploads/twine/themes')); // Custom themes URL
```

## Theme System

### Built-in Themes
- Stored in `themes/` directory
- 25+ pre-designed themes
- CSS files with metadata headers
- Enqueued after `frontend.css` (cascade override)

### Custom Themes
- Created via theme editor or uploaded
- Stored in `wp-content/uploads/twine/themes/`
- Must include metadata header in CSS
- Can be exported/shared as `.css` files

### Theme CSS Header Format
```css
/**
 * Theme Name: My Custom Theme
 * Theme URI: https://example.com/themes/my-theme
 * Description: A beautiful custom theme
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 */
```

## Recent Changes

### Version 0.1.0 (2025-11-04)

**Initial Release:**
- Profile management with icon upload
- Link management with drag-and-drop reordering
- Touch support for mobile drag-and-drop (jQuery UI Touch Punch)
- Social media integration (12 platforms)
- Theme system with 25+ built-in themes
- Custom theme support with CSS editor
- Theme file upload (.css)
- Public `/twine` endpoint
- Live preview in admin interface
- Mobile-optimized responsive design
- Git-based versioning system
- Automated build scripts (Unix/Windows)
- Runtime version reading from version.properties
- Tab-based admin interface with URL hash navigation
- WordPress Media Library integration

**Technical Improvements:**
- Removed "Linktree-style" from description
- Updated author to "Brayall, LLC"
- Changed page titles to "Twine Settings" and "Twine Themes"
- Removed colons from form labels
- Centered icons in buttons
- Added .gitignore for build artifacts
- Comprehensive README.md documentation
- Developer documentation (CLAUDE.md)

## Requirements

- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher
- **MySQL**: 5.6 or higher
- **Browser Support**: Chrome, Firefox, Safari, Edge (latest versions)

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## Author

**Brayall, LLC**
- GitHub: https://github.com/abrayall
- Repository: https://github.com/abrayall/twine
