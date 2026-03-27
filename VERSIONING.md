# JavaScript Versioning & Cache Busting

## Overview

To ensure users always get the latest JavaScript files after updates, we use query string versioning (cache busting).

## Current Versions

- **e2ee.js**: `v=1.0.1`
- **Noble libraries**: `v=1.0.0`

## How It Works

JavaScript files are loaded with version parameters:

```javascript
// In demo.html
import { ... } from '/js/e2ee.js?v=1.0.1';
```

```html
<!-- In importmap -->
<script type="importmap">
{
  "imports": {
    "@noble/hashes/crypto": "/js/noble/hashes/esm/crypto.js?v=1.0.0",
    "@noble/hashes/sha512": "/js/noble/hashes/esm/sha512.js?v=1.0.0",
    "@noble/hashes/utils": "/js/noble/hashes/esm/utils.js?v=1.0.0"
  }
}
</script>
```

## Nginx Configuration

The nginx config is set to cache JS files for 1 year with the `immutable` flag:

```nginx
location ~* \.(svg|ico|css|js|gif|jpe?g|png)(\?v=.*)?$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    log_not_found off;
}
```

This means:
- Files with `?v=1.0.0` are cached for 1 year
- When you change `?v=1.0.1`, the browser treats it as a completely new file
- Old cached versions don't interfere with new versions

## Updating JS Files

When you update JavaScript files, follow these steps:

### 1. Update e2ee.js

If you modify `/public/js/e2ee.js`:

```bash
# Edit demo.html and increment the version
# Change: from '/js/e2ee.js?v=1.0.1'
# To: from '/js/e2ee.js?v=1.0.2'
```

**Version format:** `MAJOR.MINOR.PATCH`
- **MAJOR**: Breaking changes (e.g., API changes)
- **MINOR**: New features, backwards compatible
- **PATCH**: Bug fixes

### 2. Update Noble Libraries

If you update the crypto libraries:

```bash
# Edit demo.html importmap and increment version
# Change all: ?v=1.0.0
# To: ?v=1.0.1
```

### 3. Quick Update Script

Use this one-liner to update the e2ee.js version:

```bash
# Increment patch version (e.g., 1.0.1 -> 1.0.2)
sed -i 's/e2ee\.js?v=\([0-9]\+\.[0-9]\+\.\)\([0-9]\+\)/e2ee.js?v=\1$((\2+1))/g' /usr/share/nginx/html/stringcup.com/public/demo.html
```

Or manually edit `demo.html` line ~664:

```javascript
} from '/js/e2ee.js?v=1.0.2';  // <-- Increment this
```

## Testing Cache Busting

To verify it's working:

1. Open browser DevTools (F12)
2. Go to Network tab
3. Load demo.html
4. Check the JS file request - you should see `e2ee.js?v=1.0.1`
5. Refresh the page - it should be cached (loaded from disk cache)
6. Update the version in demo.html to `v=1.0.2`
7. Refresh the page - you should see a NEW network request for `e2ee.js?v=1.0.2`

## Hard Refresh

If users report seeing old code, ask them to:
- **Chrome/Firefox**: Ctrl+Shift+R (or Cmd+Shift+R on Mac)
- **Safari**: Cmd+Option+R

This forces a full reload ignoring cache.

## Rollback

If you need to rollback to a previous version:

```bash
# Revert to previous version
git checkout HEAD~1 public/js/e2ee.js

# Update version to indicate rollback
# Change: v=1.0.2
# To: v=1.0.1
```

## Best Practices

1. **Always increment version** when deploying JS changes
2. **Use semantic versioning** (MAJOR.MINOR.PATCH)
3. **Test in incognito** after deploying to verify new version loads
4. **Document changes** in commit messages
5. **Keep version numbers in sync** with actual changes

## Troubleshooting

**Problem**: Users still see old JavaScript code

**Solutions**:
1. Verify version number was incremented in demo.html
2. Check nginx is serving the correct file
3. Clear nginx cache if applicable
4. Ask users to hard refresh (Ctrl+Shift+R)
5. Verify the file actually changed on disk

**Problem**: ImportMap errors

**Solutions**:
1. Verify all noble library versions match
2. Check file paths are correct
3. Ensure importmap is before the main script tag

## Future Improvements

Consider these enhancements:

1. **Build process**: Use webpack/vite to generate hashed filenames automatically
2. **Automated versioning**: Git commit hash as version
3. **Service Worker**: For offline support with cache management
4. **CDN integration**: If using a CDN, ensure proper cache invalidation

## Current File Structure

```
public/
├── demo.html           # Contains version references
└── js/
    ├── e2ee.js        # Main app JS (versioned)
    └── noble/
        ├── curves/
        └── hashes/
            └── esm/
                ├── crypto.js   # (versioned)
                ├── sha512.js   # (versioned)
                └── utils.js    # (versioned)
```

---

**Last Updated**: 2026-01-28
**Current e2ee.js Version**: 1.0.1
**Current Noble Version**: 1.0.0
