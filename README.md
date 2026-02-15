# Twine WordPress Plugin

A WordPress plugin that creates a beautiful link collection page for your website. Perfect for social media bio pages, landing pages, and mobile-friendly link collections.

## Features

### Profile Management
- Custom profile icon/avatar
- Name and description fields
- Upload images via WordPress Media Library
- Clean, centered profile display

### Link Management
- Simple admin interface to manage links
- Drag-and-drop reordering (touch-enabled for mobile)
- Add, edit, and remove links with ease
- Links display as styled buttons
- Mobile-optimized responsive design

### Header Icons
- Configurable icon bar at the top of the page
- 70+ built-in SVG icons across 12 categories:
  - General, Commerce & Business, Media & Content
  - Symbols & Actions, Places & Travel, Lifestyle
  - Creative & Tech, Social Networks, Messaging
  - Streaming & Music, Developer, Writing & Content
  - Support & Donations, Contact & Other
- Custom icon support via URL
- Left, center, or right alignment per icon
- Drag-and-drop reordering

### Footer Links
- Small text links at the bottom of the page (e.g. Privacy, Terms)
- Add, edit, and reorder with drag-and-drop
- Links separated by bullet separators
- Optional - only displayed when links are added

### Social Media Integration
- Dynamic social media icons with 30+ supported platforms
- SVG icons for crisp display on all devices
- Grouped display for duplicate platforms
- Optional - only displayed when URLs are provided

### Theming System
- **25+ Built-in Themes** including:
  - Minimal White (default)
  - Midnight Dark, Ocean Breeze, Sunset Glow
  - Forest Green, Fire Red, Electric Blue
  - Cosmic Purple, Golden Luxury, Neon City
  - And many more!
- **Custom Theme Support**:
  - Create custom themes with CSS
  - Built-in theme editor with syntax highlighting
  - Upload theme files (.css)
  - Preview themes before activating
  - Export and share custom themes

### Preview & Display
- Live preview in admin interface
- Public URL endpoint: `/twine`
- Clean, mobile-first design
- Smooth animations and transitions
- Touch-optimized for mobile devices

### Technical Features
- Git-based versioning system
- Build system via [wordsmith](https://github.com/abrayall/wordsmith)
- JSON-based data storage
- Responsive admin interface with tabs
- URL hash-based tab navigation

## Installation

### From ZIP File
1. Download the latest `twine-{version}.zip` from releases
2. Go to **Plugins → Add New → Upload Plugin** in WordPress admin
3. Select the ZIP file and click **Install Now**
4. Click **Activate Plugin**

### Manual Installation
1. Upload the `twine` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **Twine → Settings** in the WordPress admin menu

## Usage

### Setting Up Your Profile

1. Go to **Twine → Settings** in WordPress admin
2. Click the **Profile** tab
3. Upload an icon/avatar image
4. Enter your name and description
5. Click **Save Changes**

### Adding Links

1. Go to **Twine → Settings** in WordPress admin
2. Click the **Links** tab
3. Click **Add Link** to create a new link
4. Enter:
   - **Label**: The text displayed on the button
   - **URL**: The destination URL
5. Click **Save Changes**

### Reordering Links

- Drag and drop links using the handle icon (☰) to reorder them
- Works on both desktop and mobile/touch devices
- Links display in the same order on your public page

### Adding Header Icons

1. Go to **Twine → Settings** in WordPress admin
2. Click the **Header** tab
3. Click **Add Header Icon** and select an icon from the dropdown
4. Enter the destination URL for the icon
5. Choose alignment (Left, Center, or Right)
6. Drag to reorder icons as needed
7. Click **Save Changes**

### Adding Footer Links

1. Go to **Twine → Settings** in WordPress admin
2. Click the **Footer** tab
3. Click **Add Footer Link**
4. Enter the label text and destination URL
5. Drag to reorder links as needed
6. Click **Save Changes**

### Adding Social Media Links

1. Go to **Twine → Settings** in WordPress admin
2. Click the **Social** tab
3. Enter URLs for any social media platforms you use
4. Leave blank to hide unused platforms
5. Click **Save Changes**

### Choosing a Theme

1. Go to **Twine → Themes** in WordPress admin
2. Browse the theme gallery
3. Click on a theme card to preview in a new tab
4. Click **Use** to activate a theme
5. The active theme is highlighted with a blue border

### Creating Custom Themes

#### Using the Theme Editor

1. Go to **Twine → Themes** in WordPress admin
2. Click **Add Theme**
3. Enter a theme name and slug
4. Write your CSS in the editor
5. Use the color pickers for quick theme generation
6. Click **Preview** to see your theme
7. Click **Save Theme** to make it available

#### Uploading Theme Files

1. Create a CSS file with this header:
   ```css
   /**
    * Theme Name: My Custom Theme
    * Description: A beautiful custom theme
    * Version: 1.0.0
    * Author: Your Name
    */
   ```
2. Add your CSS rules targeting Twine classes
3. Go to **Twine → Themes** → **Upload Theme**
4. Select your `.css` file
5. Click **Use** to activate

#### Theme CSS Classes

Target these classes in your custom themes:

```css
/* Main container */
.twine-container { }

/* Header section */
.twine-header { }
.twine-header-group { }
.twine-header-left { }
.twine-header-center { }
.twine-header-right { }
.twine-header-icon { }
.twine-header-icon svg { }

/* Profile section */
.twine-profile { }
.twine-icon { }
.twine-icon img { }
.twine-name { }
.twine-description { }

/* Links section */
.twine-links { }
.twine-link-button { }
.twine-link-button:hover { }
.twine-link-button:active { }

/* Social media icons */
.twine-social { }
.twine-social-icon { }
.twine-social-icon:hover { }
.twine-social-icon svg { }

/* Footer section */
.twine-footer { }
.twine-footer-link { }
.twine-footer-link:hover { }
.twine-footer-separator { }
```

### Displaying Your Twine Page

Your Twine page is automatically available at:
```
https://yourwebsite.com/twine
```

Share this URL on your social media profiles, in your bio, or anywhere you want to direct people to all your links.

## File Storage

### Settings & Data
All settings are stored in a JSON file at:
```
wp-content/twine/settings.json
```

### Custom Themes
Custom themes are stored at:
```
wp-content/uploads/twine/themes/
```

This makes it easy to backup, migrate, or directly edit your data if needed.

## Building the Plugin

Build using [wordsmith](https://github.com/abrayall/wordsmith):

```bash
wordsmith build
```

This creates `build/twine-{version}.zip` ready for upload to WordPress.

### Versioning

Twine uses semantic versioning based on git tags:

1. **Tag a release:**
   ```bash
   git tag v0.2.0
   git push --tags
   ```

2. **Build will use tag version:**
   - Exact tag: `v0.2.0` → version `0.2.0`
   - Commits after tag: `v0.2.0-5-g1a2b3c4` → version `0.2.0-5`
   - Uncommitted changes: Appends timestamp

## Development

### File Structure
```
twine/
├── twine.php              # Main plugin file
├── version.properties     # Version info (auto-generated)
├── plugin.properties      # Build configuration for wordsmith
├── assets/
│   ├── admin.css         # Admin interface styles
│   ├── admin.js          # Admin interface JS
│   ├── twine.css         # Public page styles
│   ├── theme-editor.js   # Theme editor JS
│   └── jquery.ui.touch-punch.min.js  # Touch support
└── themes/               # Built-in theme files
    ├── minimal-white.css
    ├── midnight-dark.css
    └── ... (25+ themes)
```

### Admin Interface

The admin interface uses tabbed navigation:
- **General Tab**: Profile icon, name, and description
- **Theme Tab**: Theme selection (redirects to Themes page)
- **Links Tab**: Add, edit, reorder, remove links
- **Header Tab**: Add, edit, reorder header icons with alignment
- **Footer Tab**: Add, edit, reorder footer text links
- **Social Tab**: Social media URLs
- **Advanced Tab**: Page slug, page title, and other settings

## Requirements

- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher
- **MySQL**: 5.6 or higher

## Browser Support

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers (iOS Safari, Chrome Mobile, Samsung Internet)

## Responsive Design

Twine is mobile-first and fully responsive:
- Desktop: Side-by-side layout, hover effects
- Tablet: Optimized spacing
- Mobile: Stacked layout, touch-friendly buttons
- Touch devices: Drag-and-drop enabled with Touch Punch

## Changelog

### Version 0.1.7 (2026-02-15)
- Header icon bar with 70+ icons and left/center/right alignment
- Footer text links section
- Widen align dropdown for full text visibility

### Version 0.1.0 (2025-11-04)
- Initial release
- Profile management with icon upload
- Link management with drag-and-drop
- 25+ built-in themes
- Custom theme support
- Theme editor with syntax highlighting
- Social media integration with 30+ platforms
- Public `/twine` endpoint
- Mobile-optimized responsive design
- Git-based versioning system

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## Credits

**Author**: Brayall, LLC

## Support

For issues and feature requests, please visit:
https://github.com/abrayall/twine/issues
