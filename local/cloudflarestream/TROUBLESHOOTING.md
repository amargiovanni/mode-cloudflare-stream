# Troubleshooting Guide - Cloudflare Stream Integration

This guide helps you diagnose and resolve common issues with the Cloudflare Stream Integration plugin for Moodle.

## 🔍 Diagnostic Tools

### Admin Dashboard
Access the plugin's admin dashboard for real-time system status:
- **Location**: Site Administration > Plugins > Local plugins > Cloudflare Stream Dashboard
- **Information Available**:
  - Video statistics and status counts
  - Queue status and processing information
  - System health indicators
  - Recent upload activity

### Debug Mode
Enable debug mode for detailed error information:
```php
// Add to config.php
$CFG->debug = DEBUG_DEVELOPER;
$CFG->debugdisplay = 1;
$CFG->local_cloudflarestream_debug = true;
```

### Log Locations
- **Moodle Logs**: Site Administration > Reports > Logs
- **Plugin Logs**: Admin Dashboard > System Status
- **Web Server Logs**: `/var/log/apache2/error.log` or `/var/log/nginx/error.log`
- **PHP Logs**: Check `php.ini` for `log_errors` and `error_log` settings

## 🚨 Common Issues and Solutions

### Installation and Configuration Issues

#### Issue: Plugin Installation Fails
**Symptoms**:
- Error during Moodle database upgrade
- Plugin not appearing in plugin list
- Database table creation errors

**Solutions**:
1. **Check File Permissions**:
   ```bash
   chown -R www-data:www-data /path/to/moodle/local/cloudflarestream
   chmod -R 755 /path/to/moodle/local/cloudflarestream
   ```

2. **Verify Database Permissions**:
   - Ensure Moodle database user has CREATE and ALTER privileges
   - Check database connection in Moodle config

3. **Clear Caches**:
   ```bash
   # Clear Moodle caches
   php admin/cli/purge_caches.php
   ```

4. **Manual Database Installation**:
   ```sql
   -- Run the SQL from db/install.xml manually if needed
   -- Check Moodle documentation for xmldb format
   ```

#### Issue: API Connection Test Fails
**Symptoms**:
- "Connection failed" message in settings
- API credentials validation errors
- Timeout errors during connection test

**Solutions**:
1. **Verify API Token**:
   - Check token has `Stream:Edit` permissions
   - Ensure token hasn't expired
   - Test token with curl:
   ```bash
   curl -X GET "https://api.cloudflare.com/client/v4/accounts/YOUR_ACCOUNT_ID/stream" \
        -H "Authorization: Bearer YOUR_API_TOKEN"
   ```

2. **Check Account ID Format**:
   - Must be exactly 32 hexadecimal characters
   - Found in Cloudflare dashboard right sidebar
   - No spaces or special characters

3. **Network Connectivity**:
   - Ensure server can make outbound HTTPS connections
   - Check firewall rules for port 443
   - Verify DNS resolution for api.cloudflare.com

4. **Server Configuration**:
   ```php
   // Check PHP configuration
   phpinfo(); // Look for cURL and OpenSSL extensions
   ```

### Upload and Processing Issues

#### Issue: Videos Not Uploading to Cloudflare
**Symptoms**:
- Videos remain in "pending" status
- Queue items not processing
- Upload errors in logs

**Solutions**:
1. **Check Queue Processing**:
   - Verify scheduled tasks are running
   - Check task execution logs
   - Manually run queue processing:
   ```bash
   php admin/cli/scheduled_task.php --execute='\local_cloudflarestream\task\process_queue'
   ```

2. **File Size and Format Validation**:
   - Check file size against plugin limits
   - Verify file format is supported
   - Review PHP upload limits:
   ```php
   // Check these values in php.ini
   upload_max_filesize = 1G
   post_max_size = 1G
   max_execution_time = 300
   memory_limit = 512M
   ```

3. **API Rate Limiting**:
   - Cloudflare has API rate limits
   - Reduce batch processing size
   - Add delays between API calls

4. **Storage Issues**:
   - Check available disk space
   - Verify temporary directory permissions
   - Clean up old temporary files

#### Issue: Videos Stuck in "Processing" Status
**Symptoms**:
- Videos uploaded to Cloudflare but never become "ready"
- Status sync not working
- Videos playable on Cloudflare but not in Moodle

**Solutions**:
1. **Manual Status Sync**:
   - Use admin dashboard sync tools
   - Run sync task manually:
   ```bash
   php admin/cli/scheduled_task.php --execute='\local_cloudflarestream\task\sync_videos'
   ```

2. **Check Cloudflare Processing**:
   - Log into Cloudflare dashboard
   - Check Stream section for video status
   - Verify video actually finished processing

3. **Database Inconsistencies**:
   ```sql
   -- Check for stuck videos
   SELECT * FROM mdl_local_cloudflarestream_videos 
   WHERE status = 'processing' 
   AND upload_date < (UNIX_TIMESTAMP() - 7200); -- 2 hours ago
   ```

4. **Reset Stuck Videos**:
   - Use admin dashboard to reset videos
   - Or manually update database status

### Player and Access Issues

#### Issue: Video Player Not Loading
**Symptoms**:
- Blank space where player should be
- "Access denied" errors
- Player shows loading indefinitely

**Solutions**:
1. **Check User Permissions**:
   - Verify user is enrolled in course
   - Check Moodle capability permissions
   - Test with different user roles

2. **Token Issues**:
   - Check if tokens are expiring too quickly
   - Verify token generation is working
   - Test token validation:
   ```php
   // Debug token validation
   $validation = \local_cloudflarestream\auth\token_manager::validate_access_token($token, $video_id);
   var_dump($validation);
   ```

3. **Browser Issues**:
   - Test in different browsers
   - Check browser console for JavaScript errors
   - Disable browser extensions
   - Clear browser cache and cookies

4. **Content Security Policy**:
   - Check if CSP headers block Cloudflare domains
   - Add Cloudflare domains to CSP whitelist:
   ```
   Content-Security-Policy: frame-src 'self' *.cloudflarestream.com;
   ```

#### Issue: "Access Denied" Errors
**Symptoms**:
- Users get permission errors when trying to view videos
- Videos work for some users but not others
- Inconsistent access patterns

**Solutions**:
1. **Course Enrollment**:
   - Verify users are properly enrolled
   - Check enrollment dates and status
   - Review course visibility settings

2. **Role Permissions**:
   - Check role definitions and capabilities
   - Verify context-level permissions
   - Test with different roles

3. **Video Ownership**:
   - Check if access control is too restrictive
   - Verify video course assignment
   - Review sharing settings

### Performance Issues

#### Issue: Slow Upload Processing
**Symptoms**:
- Long delays between upload and processing
- Queue backlog building up
- Server performance degradation

**Solutions**:
1. **Server Resources**:
   ```bash
   # Monitor server resources
   top
   htop
   df -h  # Check disk space
   free -m  # Check memory usage
   ```

2. **PHP Configuration**:
   ```php
   // Optimize PHP settings
   memory_limit = 1G
   max_execution_time = 600
   max_input_time = 600
   ```

3. **Queue Processing**:
   - Increase queue processing frequency
   - Process queue items in smaller batches
   - Add more queue processing workers

4. **Database Optimization**:
   ```sql
   -- Add indexes if missing
   SHOW INDEX FROM mdl_local_cloudflarestream_videos;
   SHOW INDEX FROM mdl_local_cloudflarestream_queue;
   ```

#### Issue: High Server Load
**Symptoms**:
- Server becomes unresponsive during video processing
- Other Moodle functions slow down
- Memory or CPU usage spikes

**Solutions**:
1. **Resource Limits**:
   - Implement processing limits
   - Add delays between operations
   - Process during off-peak hours

2. **Background Processing**:
   - Ensure processing happens in background
   - Use proper queue management
   - Implement job prioritization

3. **Monitoring**:
   - Set up server monitoring
   - Create alerts for high resource usage
   - Monitor queue sizes

### Maintenance and Cleanup Issues

#### Issue: Storage Space Running Out
**Symptoms**:
- Disk space warnings
- Upload failures due to space
- Temporary files accumulating

**Solutions**:
1. **Enable Automatic Cleanup**:
   - Configure cleanup delay appropriately
   - Ensure cleanup tasks are running
   - Monitor cleanup effectiveness

2. **Manual Cleanup**:
   ```bash
   # Find large temporary files
   find /path/to/moodledata -name "*.tmp" -size +100M -ls
   
   # Clean up old files (be careful!)
   find /path/to/moodledata/temp -mtime +7 -delete
   ```

3. **Storage Monitoring**:
   - Set up disk space monitoring
   - Create alerts for low space
   - Plan storage capacity

#### Issue: Orphaned Files and Videos
**Symptoms**:
- Videos in Cloudflare not in Moodle database
- Local files without corresponding videos
- Database inconsistencies

**Solutions**:
1. **Use Cleanup Tools**:
   - Run orphan cleanup from admin dashboard
   - Use dry-run mode first to see what would be cleaned

2. **Manual Investigation**:
   ```sql
   -- Find orphaned database records
   SELECT v.* FROM mdl_local_cloudflarestream_videos v
   LEFT JOIN mdl_files f ON f.id = v.moodle_file_id
   WHERE f.id IS NULL;
   ```

3. **Sync Operations**:
   - Run full synchronization
   - Compare Moodle and Cloudflare inventories
   - Resolve discrepancies manually if needed

## 🔧 Advanced Troubleshooting

### Database Debugging
```sql
-- Check video status distribution
SELECT status, COUNT(*) as count 
FROM mdl_local_cloudflarestream_videos 
GROUP BY status;

-- Find old pending videos
SELECT * FROM mdl_local_cloudflarestream_videos 
WHERE status = 'pending' 
AND upload_date < (UNIX_TIMESTAMP() - 3600);

-- Check queue status
SELECT action, COUNT(*) as count, AVG(attempts) as avg_attempts
FROM mdl_local_cloudflarestream_queue 
GROUP BY action;
```

### API Debugging
```bash
# Test API connectivity
curl -v -X GET "https://api.cloudflare.com/client/v4/accounts/YOUR_ACCOUNT_ID/stream" \
     -H "Authorization: Bearer YOUR_API_TOKEN"

# Check specific video
curl -X GET "https://api.cloudflare.com/client/v4/accounts/YOUR_ACCOUNT_ID/stream/VIDEO_ID" \
     -H "Authorization: Bearer YOUR_API_TOKEN"
```

### Log Analysis
```bash
# Search for plugin-related errors
grep -i "cloudflarestream" /var/log/apache2/error.log
grep -i "stream" /path/to/moodle/config.php

# Monitor real-time logs
tail -f /var/log/apache2/error.log | grep cloudflare
```

## 📞 Getting Help

### Before Contacting Support
1. **Gather Information**:
   - Plugin version and Moodle version
   - Error messages (exact text)
   - Steps to reproduce the issue
   - Server environment details
   - Recent changes or updates

2. **Try Basic Solutions**:
   - Clear all caches
   - Restart web server
   - Check recent log entries
   - Test with minimal configuration

3. **Document the Issue**:
   - Screenshots of error messages
   - Relevant log entries
   - Configuration settings
   - Timeline of when issue started

### Support Channels
- **Community Forums**: Moodle.org forums
- **GitHub Issues**: Plugin repository issues
- **Documentation**: Check README and wiki
- **Professional Support**: Contact plugin maintainers

### Information to Include
- **Environment**: OS, PHP version, Moodle version, plugin version
- **Configuration**: Relevant settings (sanitize sensitive data)
- **Error Messages**: Complete error text and stack traces
- **Steps to Reproduce**: Detailed steps that trigger the issue
- **Expected vs Actual**: What should happen vs what actually happens

---

Remember: Most issues can be resolved by checking logs, verifying configuration, and ensuring all requirements are met. Take time to understand the error messages and work through the solutions systematically.