
npm install -g opencode-ai

rm -rf "$USERPROFILE/.config/opencode"
rm -rf "$USERPROFILE/.opencode"
rm -rf "$USERPROFILE/AppData/Roaming/opencode"

npm uninstall -g opencode-ai


//Deploymnt commands.

ln -s /home/u214605677/domains/staging-api.custosell.com/public /home/u214605677/domains/custosell.com/public_html/staging-api
ln -s /home/u214605677/domains/staging-api.custosell.com/storage/app/public /home/u214605677/domains/staging-api.custosell.com/public/storage

Run these on the server, in order:
Step 1 - Go to the app folder
cd ~/domains/staging-api.custosell.com
Step 2 - Fix the current divergence (one-time)
git fetch origin
git reset --hard origin/main
Step 3 - Make future pulls safe (permanent)
git config pull.ff only
Step 4 - Deploy from now on (always works)
git fetch origin && git reset --hard origin/main




<IfModule mod_rewrite.c>
  RewriteEngine On
  
  # Handle client-side routing
  RewriteBase /
  
  # IMPORTANT: Catch any path that includes /assets/ and serve from root assets
  # This handles patterns like:
  # /anything/assets/file.png -> /assets/file.png
  # /administration/assets/icon.png -> /assets/icon.png
  # /subscriptions/payments/assets/file.js -> /assets/file.js
  RewriteRule ^[^/]+/assets/(.*)$ /assets/$1 [L]
  
  # Also catch two-level deep paths with assets (more specific)
  RewriteRule ^([^/]+)/([^/]+)/assets/(.*)$ /assets/$3 [L]
  
  # Don't rewrite files or directories that exist
  RewriteCond %{REQUEST_FILENAME} -f [OR]
  RewriteCond %{REQUEST_FILENAME} -d
  RewriteRule ^ - [L]
  
  # Redirect all other requests to index.html (for SPA routing)
  RewriteRule . /index.html [L]
</IfModule>

# Enable compression for better performance
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/plain
  AddOutputFilterByType DEFLATE text/html
  AddOutputFilterByType DEFLATE text/xml
  AddOutputFilterByType DEFLATE text/css
  AddOutputFilterByType DEFLATE application/xml
  AddOutputFilterByType DEFLATE application/xhtml+xml
  AddOutputFilterByType DEFLATE application/rss+xml
  AddOutputFilterByType DEFLATE application/javascript
  AddOutputFilterByType DEFLATE application/x-javascript
  AddOutputFilterByType DEFLATE application/json
  AddOutputFilterByType DEFLATE image/svg+xml
</IfModule>

# Cache static assets for better performance
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/jpg "access plus 1 year"
  ExpiresByType image/jpeg "access plus 1 year"
  ExpiresByType image/gif "access plus 1 year"
  ExpiresByType image/png "access plus 1 year"
  ExpiresByType image/svg+xml "access plus 1 year"
  ExpiresByType text/css "access plus 1 month"
  ExpiresByType application/javascript "access plus 1 month"
  ExpiresByType application/x-javascript "access plus 1 month"
  ExpiresByType font/woff "access plus 1 year"
  ExpiresByType font/woff2 "access plus 1 year"
</IfModule>

# Security headers
<IfModule mod_headers.c>
  Header set X-Content-Type-Options "nosniff"
  Header set X-Frame-Options "SAMEORIGIN"
  Header set X-XSS-Protection "1; mode=block"
  
  # Add CORS headers for assets (helps with fonts and cross-origin requests)
  <FilesMatch "\.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$">
    Header set Access-Control-Allow-Origin "*"
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>

# Prevent access to sensitive files
<FilesMatch "^\.">
  Order allow,deny
  Deny from all
</FilesMatch>

<FilesMatch "\.(htaccess|htpasswd|ini|log|sh|sql|json|lock)$">
  Order allow,deny
  Deny from all
</FilesMatch>

# Set default charset
AddDefaultCharset UTF-8

# Enable Keep-Alive
<IfModule mod_headers.c>
  Header set Connection keep-alive
</IfModule>

Full Name
OPIYO OSCAR
Track / Role
Web Development
Status
Pending
Certificate Code
PPI-2026-AADC91