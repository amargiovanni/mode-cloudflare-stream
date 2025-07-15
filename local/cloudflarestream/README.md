# Cloudflare Stream Integration for Moodle

A Moodle local plugin that integrates with Cloudflare Stream to provide secure, high-performance video streaming for e-learning courses.

## Features

- **Automatic Video Upload**: Videos uploaded to Moodle are automatically transferred to Cloudflare Stream
- **Secure Streaming**: Only authenticated users can access videos through time-limited tokens
- **Performance Optimization**: Videos are served from Cloudflare's global CDN
- **Admin Dashboard**: Monitor video status, usage statistics, and manage configurations
- **Privacy Compliant**: GDPR-compliant data handling and user privacy controls
- **Automatic Cleanup**: Intelligent file management and storage optimization

## Requirements

- Moodle 3.9 or higher
- PHP 7.4 or higher
- cURL extension enabled
- OpenSSL extension for encryption
- Valid Cloudflare Stream account

## Installation

1. Download or clone this plugin to your Moodle installation
2. Place the plugin in `/path/to/moodle/local/cloudflarestream/`
3. Log in as an administrator and visit the notifications page to complete installation
4. Configure your Cloudflare Stream credentials in Site Administration > Plugins > Local plugins > Cloudflare Stream Integration

## Configuration

### Required Settings

1. **API Token**: Your Cloudflare API token with Stream:Edit permissions
2. **Account ID**: Your Cloudflare Account ID

### Optional Settings

- **Zone ID**: For custom domain configuration
- **Maximum File Size**: Upload size limit (default: 500MB)
- **Supported Formats**: Video file formats to process
- **Token Expiry**: How long access tokens remain valid
- **Player Settings**: Controls, autoplay, and other player options
- **Cleanup Delay**: How long to keep local files after upload

## Usage

Once configured, the plugin works automatically:

1. **For Teachers**: Upload videos through Moodle's standard file picker - they'll be automatically sent to Cloudflare Stream
2. **For Students**: Videos will stream securely from Cloudflare's CDN with authentication
3. **For Administrators**: Monitor usage and manage settings through the admin dashboard

## Security

- All API credentials are encrypted in the database
- Access tokens are time-limited and user-specific
- Video access is controlled by Moodle's permission system
- All communications use HTTPS/TLS encryption

## Support

For issues, feature requests, or contributions, please visit the plugin's repository or contact the maintainer.

## License

This plugin is licensed under the GNU General Public License v3.0 or later.