# Cloudflare Stream Integration for Moodle

A comprehensive Moodle plugin that integrates Cloudflare Stream video hosting service, providing secure, scalable, and optimized video streaming capabilities for educational content.

## 🎯 Features

### Core Functionality
- **Automatic Video Processing**: Seamlessly uploads video files to Cloudflare Stream
- **Secure Access Control**: JWT-based authentication with role-based permissions
- **Responsive Video Player**: Mobile-friendly player with customizable controls
- **Background Processing**: Queue-based upload system for optimal performance
- **Real-time Monitoring**: Comprehensive admin dashboard with statistics

### Security & Privacy
- **GDPR Compliance**: Full privacy API implementation
- **Token-based Security**: Secure video access with expiring tokens
- **Domain Restrictions**: Optional domain-based access control
- **User Permission Integration**: Respects Moodle's role and capability system

### Administration & Maintenance
- **Health Monitoring**: Automated system health checks and alerts
- **Sync Management**: Manual and automatic video synchronization tools
- **Cleanup Automation**: Configurable file cleanup and maintenance
- **Error Recovery**: Automatic retry mechanisms for failed uploads

## 📋 Requirements

- **Moodle**: 4.0 or higher
- **PHP**: 7.4 or higher
- **Cloudflare Account**: With Stream service enabled
- **Storage**: Adequate local storage for temporary file processing

## 🚀 Installation

### Step 1: Download and Extract
```bash
# Download the plugin
wget https://github.com/your-repo/moodle-local_cloudflarestream/archive/main.zip

# Extract to Moodle directory
unzip main.zip -d /path/to/moodle/local/
mv moodle-local_cloudflarestream-main cloudflarestream
```

### Step 2: Complete Installation
1. Visit **Site Administration > Notifications**
2. Follow the installation prompts
3. Click **Upgrade Moodle database now**

### Step 3: Configure Plugin
1. Navigate to **Site Administration > Plugins > Local plugins > Cloudflare Stream**
2. Enter your Cloudflare credentials (see Configuration section)
3. Test the API connection
4. Save settings

## ⚙️ Configuration

### Required Settings

#### Cloudflare API Configuration
- **API Token**: Your Cloudflare API token with Stream:Edit permissions
  ```
  How to get: Cloudflare Dashboard > My Profile > API Tokens > Create Token
  Required permissions: Zone:Zone:Read, Zone:Stream:Edit
  ```
- **Account ID**: Your Cloudflare Account ID
  ```
  Location: Right sidebar of Cloudflare Dashboard
  Format: 32-character hexadecimal string
  ```

### Optional Settings

#### Upload Configuration
- **Maximum File Size**: Maximum video file size in bytes (default: 500MB)
- **Supported Formats**: Comma-separated video formats (default: mp4,mov,avi,mkv,webm)

#### Player Configuration
- **Token Expiry**: Access token validity period in seconds (default: 3600)
- **Player Controls**: Show/hide video player controls
- **Autoplay**: Enable/disable automatic video playback

#### Maintenance Configuration
- **Cleanup Delay**: Time to keep local files after upload (default: 7 days)

#### Security Configuration
- **Domain Restrictions**: Limit video playback to specific domains
- **Referrer Restrictions**: Control access based on HTTP referrer
- **Fallback Player**: Enable HTML5 fallback for compatibility

## 📖 Usage Guide

### For Administrators

#### Initial Setup
1. **Configure API Credentials**
   - Obtain Cloudflare API token and Account ID
   - Test connection using the built-in test tool
   - Configure upload and player settings

2. **Monitor System Health**
   - Access the admin dashboard regularly
   - Review upload statistics and error logs
   - Use manual sync tools when needed

#### Ongoing Management
- **Regular Maintenance**: Use cleanup tools to manage storage
- **Performance Monitoring**: Check queue status and processing times
- **User Support**: Help users with video upload issues

### For Teachers

#### Uploading Videos
1. **Course Content**: Upload video files directly to course sections
2. **Automatic Processing**: Videos are automatically sent to Cloudflare Stream
3. **Status Monitoring**: Check upload progress in course management

#### Managing Videos
- **View Status**: See processing status of uploaded videos
- **Access Control**: Videos respect course enrollment and permissions
- **Player Customization**: Use configured player settings

### For Students

#### Viewing Videos
1. **Secure Access**: Videos require proper course enrollment
2. **Optimized Playback**: Automatic quality adjustment based on connection
3. **Mobile Friendly**: Responsive player works on all devices

## 🔧 Advanced Configuration

### Custom Domain Setup
```php
// In config.php or through admin interface
$CFG->local_cloudflarestream_zone_id = 'your_zone_id_here';
```

### Performance Tuning
```php
// Adjust queue processing frequency
$CFG->local_cloudflarestream_queue_frequency = 300; // 5 minutes

// Set upload batch size
$CFG->local_cloudflarestream_batch_size = 5;
```

### Security Hardening
```php
// Enable strict domain checking
$CFG->local_cloudflarestream_strict_domains = true;

// Set custom token expiry
$CFG->local_cloudflarestream_token_expiry = 1800; // 30 minutes
```

## 🛠️ Troubleshooting

### Common Issues

#### Upload Failures
**Problem**: Videos fail to upload to Cloudflare
**Solutions**:
- Check API credentials and permissions
- Verify file size limits
- Review error logs in admin dashboard
- Use manual sync tools to retry failed uploads

#### Player Not Loading
**Problem**: Video player doesn't appear or shows errors
**Solutions**:
- Verify video processing is complete
- Check user permissions and course enrollment
- Test with different browsers
- Review token expiry settings

#### Performance Issues
**Problem**: Slow upload processing or high server load
**Solutions**:
- Adjust queue processing frequency
- Increase PHP memory limits
- Enable file cleanup automation
- Monitor server resources

### Debug Mode
Enable debug logging for detailed troubleshooting:
```php
// In config.php
$CFG->debug = DEBUG_DEVELOPER;
$CFG->debugdisplay = 1;
$CFG->local_cloudflarestream_debug = true;
```

### Log Locations
- **Moodle Logs**: Site Administration > Reports > Logs
- **Plugin Logs**: Admin Dashboard > System Status
- **Server Logs**: Check your web server error logs

## 🔄 Maintenance

### Regular Tasks
- **Weekly**: Review admin dashboard for errors
- **Monthly**: Run cleanup tools to manage storage
- **Quarterly**: Update API tokens if needed

### Automated Maintenance
The plugin includes several automated maintenance tasks:
- **File Cleanup**: Removes local files after successful upload
- **Token Cleanup**: Removes expired access tokens
- **Video Sync**: Synchronizes status with Cloudflare Stream
- **Health Checks**: Monitors system health and sends alerts

### Manual Maintenance Tools
Access through Admin Dashboard:
- **Sync Videos**: Update video status from Cloudflare
- **Cleanup Orphans**: Remove orphaned videos and files
- **Retry Failed**: Retry failed upload operations
- **System Health**: Run comprehensive health checks

## 🔒 Security Considerations

### Data Protection
- **Encryption**: API tokens are encrypted in database
- **Access Control**: Videos respect Moodle permissions
- **Privacy Compliance**: Full GDPR implementation
- **Audit Logging**: Comprehensive access logging

### Best Practices
- **Regular Updates**: Keep plugin updated
- **Token Rotation**: Rotate API tokens periodically
- **Permission Review**: Regularly review user permissions
- **Backup Strategy**: Include plugin data in backups

## 🧪 Testing

### Unit Tests
Run the complete test suite:
```bash
# From Moodle root directory
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/cloudflarestream/tests/
```

### Integration Tests
Test specific functionality:
```bash
# Test upload workflow
vendor/bin/phpunit local/cloudflarestream/tests/upload_workflow_test.php

# Test player integration
vendor/bin/phpunit local/cloudflarestream/tests/player_integration_test.php
```

## 📊 Performance Metrics

### Typical Performance
- **Upload Processing**: 2-5 minutes for standard videos
- **Token Generation**: < 100ms
- **Player Loading**: < 2 seconds
- **Sync Operations**: 30-60 seconds for 100 videos

### Optimization Tips
- **File Formats**: Use MP4 for best compatibility
- **File Sizes**: Keep under 1GB for optimal processing
- **Batch Operations**: Process multiple videos during off-peak hours
- **Caching**: Enable Moodle caching for better performance

## 🤝 Contributing

### Development Setup
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests to ensure compatibility
5. Submit a pull request

### Code Standards
- Follow Moodle coding standards
- Include PHPDoc comments
- Add unit tests for new features
- Update documentation as needed

## 📞 Support

### Community Support
- **Moodle Forums**: Post in the Local Plugins forum
- **GitHub Issues**: Report bugs and feature requests
- **Documentation**: Check the wiki for detailed guides

### Professional Support
For enterprise support and custom development:
- Email: support@yourcompany.com
- Website: https://yourcompany.com/moodle-support

## 📄 License

This plugin is licensed under the GNU General Public License v3.0 or later.
See [LICENSE](LICENSE) for full license text.

## 🙏 Acknowledgments

- **Moodle Community**: For the excellent platform and documentation
- **Cloudflare**: For the robust Stream API
- **Contributors**: All developers who have contributed to this project

---

**Version**: 1.0.0  
**Compatibility**: Moodle 4.0+  
**Last Updated**: January 2025