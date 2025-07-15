# Installation Guide - Cloudflare Stream Integration for Moodle

This guide provides detailed instructions for installing and configuring the Cloudflare Stream Integration plugin for Moodle.

## Prerequisites

### System Requirements
- **Moodle**: Version 4.0 or higher
- **PHP**: Version 7.4 or higher with the following extensions:
  - cURL (for API communication)
  - OpenSSL (for encryption)
  - JSON (for data handling)
  - mbstring (for string handling)
- **Database**: MySQL 5.7+, PostgreSQL 10+, or MariaDB 10.2+
- **Web Server**: Apache 2.4+ or Nginx 1.14+

### Cloudflare Requirements
- Active Cloudflare account
- Cloudflare Stream service enabled
- API token with appropriate permissions

## Step 1: Prepare Cloudflare Account

### 1.1 Enable Cloudflare Stream
1. Log in to your Cloudflare dashboard
2. Navigate to **Stream** in the left sidebar
3. If not already enabled, click **Enable Stream**
4. Note your **Account ID** (displayed in the right sidebar)

### 1.2 Create API Token
1. Go to **My Profile > API Tokens**
2. Click **Create Token**
3. Use the **Custom token** template
4. Configure the token with these permissions:
   - **Account**: `Cloudflare Stream:Edit`
   - **Zone**: `Zone:Read` (if using custom domains)
5. Set **Account Resources** to include your account
6. Click **Continue to summary** and then **Create Token**
7. **Important**: Copy and save the token immediately (it won't be shown again)

## Step 2: Download and Install Plugin

### 2.1 Download Plugin
Choose one of these methods:

#### Method A: Direct Download
1. Download the latest release from the plugin repository
2. Extract the ZIP file

#### Method B: Git Clone
```bash
cd /path/to/moodle/local/
git clone https://github.com/your-repo/moodle-local_cloudflarestream.git cloudflarestream
```

### 2.2 Install Plugin Files
1. Copy the plugin files to your Moodle installation:
   ```bash
   cp -r cloudflarestream /path/to/moodle/local/
   ```
2. Ensure proper file permissions:
   ```bash
   chown -R www-data:www-data /path/to/moodle/local/cloudflarestream
   chmod -R 755 /path/to/moodle/local/cloudflarestream
   ```

### 2.3 Complete Moodle Installation
1. Log in to Moodle as an administrator
2. Navigate to **Site Administration > Notifications**
3. You should see a notification about the new plugin
4. Click **Upgrade Moodle database now**
5. Follow the installation prompts
6. Click **Continue** when installation is complete

## Step 3: Configure Plugin

### 3.1 Access Plugin Settings
1. Go to **Site Administration > Plugins > Local plugins**
2. Click **Cloudflare Stream Integration**

### 3.2 Configure API Settings
1. **API Token**: Enter the token you created in Step 1.2
2. **Account ID**: Enter your Cloudflare Account ID from Step 1.1
3. **Zone ID**: (Optional) Enter if using custom domains
4. Click **Test Connection** to verify credentials
5. You should see a success message if configured correctly

### 3.3 Configure Upload Settings
1. **Maximum File Size**: Set appropriate limit (default: 500MB)
   - Consider your server's upload limits
   - Check `php.ini` settings: `upload_max_filesize` and `post_max_size`
2. **Supported Formats**: Specify video formats to process
   - Default: `mp4,mov,avi,mkv,webm`
   - Add or remove formats as needed

### 3.4 Configure Player Settings
1. **Token Expiry**: Set how long access tokens remain valid
   - Default: 3600 seconds (1 hour)
   - Balance security vs. user experience
2. **Player Controls**: Enable/disable video controls
3. **Autoplay**: Enable/disable automatic playback

### 3.5 Configure Maintenance Settings
1. **Cleanup Delay**: Set how long to keep local files
   - Default: 604800 seconds (7 days)
   - Longer delays use more storage but provide recovery options
2. Save all settings

## Step 4: Verify Installation

### 4.1 Test API Connection
1. In the plugin settings, click **Test Connection**
2. Verify you see: "Connection successful! Your Cloudflare Stream API credentials are working correctly."
3. If you see an error, double-check your API token and Account ID

### 4.2 Check Scheduled Tasks
1. Go to **Site Administration > Server > Scheduled tasks**
2. Search for "cloudflare" to find plugin tasks:
   - Process Cloudflare Stream upload queue
   - Sync video status with Cloudflare Stream
   - Clean up local video files
   - Clean up expired access tokens
3. Verify all tasks are enabled and have reasonable schedules

### 4.3 Test Upload Functionality
1. Create a test course or use an existing one
2. Upload a small video file to the course
3. Check the admin dashboard to see if the video appears in the queue
4. Monitor processing status

## Step 5: Configure Advanced Settings (Optional)

### 5.1 Security Settings
1. **Domain Restrictions**: Enable if you want to restrict video playback to specific domains
2. **Allowed Domains**: List domains that can embed videos
3. **Referrer Restrictions**: Enable to check HTTP referrer headers
4. **Fallback Player**: Enable HTML5 fallback for compatibility

### 5.2 Performance Optimization
1. **PHP Settings**: Optimize for video processing
   ```ini
   # In php.ini
   memory_limit = 512M
   max_execution_time = 300
   upload_max_filesize = 1G
   post_max_size = 1G
   ```

2. **Moodle Caching**: Enable caching for better performance
   - Go to **Site Administration > Plugins > Caching**
   - Enable application and session caching

### 5.3 Monitoring Setup
1. **Admin Dashboard**: Bookmark the plugin's admin dashboard
2. **Notifications**: Configure email notifications for administrators
3. **Log Monitoring**: Set up log monitoring for error detection

## Step 6: User Training and Documentation

### 6.1 Administrator Training
- Familiarize administrators with the admin dashboard
- Document troubleshooting procedures
- Set up monitoring and maintenance schedules

### 6.2 Teacher Training
- Show teachers how to upload videos
- Explain processing times and status indicators
- Provide guidelines for video formats and sizes

### 6.3 Student Information
- Inform students about video access requirements
- Provide technical support contact information
- Document browser compatibility information

## Troubleshooting Installation Issues

### Common Installation Problems

#### Database Errors
**Problem**: Database installation fails
**Solutions**:
- Check database permissions
- Verify Moodle database configuration
- Review error logs for specific issues
- Ensure database has sufficient space

#### Permission Errors
**Problem**: File permission errors during installation
**Solutions**:
```bash
# Fix ownership
chown -R www-data:www-data /path/to/moodle/local/cloudflarestream

# Fix permissions
find /path/to/moodle/local/cloudflarestream -type d -exec chmod 755 {} \;
find /path/to/moodle/local/cloudflarestream -type f -exec chmod 644 {} \;
```

#### API Connection Failures
**Problem**: Cannot connect to Cloudflare API
**Solutions**:
- Verify API token has correct permissions
- Check Account ID format (32 hex characters)
- Ensure server can make outbound HTTPS connections
- Check firewall settings

#### PHP Extension Issues
**Problem**: Missing required PHP extensions
**Solutions**:
```bash
# Ubuntu/Debian
sudo apt-get install php-curl php-openssl php-json php-mbstring

# CentOS/RHEL
sudo yum install php-curl php-openssl php-json php-mbstring

# Restart web server after installation
sudo systemctl restart apache2  # or nginx
```

### Getting Help

If you encounter issues during installation:

1. **Check Logs**: Review Moodle and web server error logs
2. **Documentation**: Consult the main README.md file
3. **Community**: Post in Moodle forums with specific error messages
4. **Support**: Contact plugin maintainers with detailed information

### Post-Installation Checklist

- [ ] Plugin installed successfully
- [ ] API connection test passes
- [ ] Scheduled tasks are enabled
- [ ] Test video upload works
- [ ] Admin dashboard accessible
- [ ] User permissions configured
- [ ] Documentation provided to users
- [ ] Monitoring and maintenance scheduled

## Next Steps

After successful installation:

1. **Monitor Initial Usage**: Watch the first few video uploads closely
2. **Gather Feedback**: Collect user feedback and address issues
3. **Optimize Settings**: Adjust configuration based on usage patterns
4. **Plan Maintenance**: Schedule regular maintenance tasks
5. **Stay Updated**: Monitor for plugin updates and security patches

---

For additional support and advanced configuration options, refer to the main README.md file or contact the plugin maintainers.