# Twine WordPress Plugin

A WordPress plugin that creates a Linktree-style link collection for your website. Perfect for social media bio pages, landing pages, and mobile-friendly link collections.

## Features

- Simple admin interface to manage links
- Drag-and-drop reordering
- Mobile-optimized display
- Linktree-style button design
- Dark mode support
- Stores links in JSON file (`wp-content/twine/links.json`)

## Installation

1. Upload the `twine` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to 'Twine' in the WordPress admin menu

## Usage

### Adding Links

1. Go to **Twine** in your WordPress admin menu
2. Click **Add Link** to create a new link
3. Enter the link text and URL
4. Click **Save Links**

### Reordering Links

- Drag and drop links using the handle icon (☰) to reorder them
- Links will display in the same order on your page

### Displaying Links

Add the shortcode to any page or post:

```
[twine]
```

The links will be displayed as styled buttons, similar to Linktree.

## File Storage

Links are stored in a JSON file at:
```
wp-content/twine/links.json
```

This makes it easy to backup, migrate, or directly edit your links if needed.

## Customization

### Styling

You can customize the appearance by overriding the CSS classes:

- `.twine-container` - Main container
- `.twine-links` - Links wrapper
- `.twine-link-button` - Individual link buttons

Add custom CSS through your theme's stylesheet or the WordPress Customizer.

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher

## Browser Support

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)

## License

GPL v2 or later

## Support

For issues and feature requests, please contact the plugin author.
