# WordPress.org Release Checklist

## Before submission

- Verify the plugin works on native WordPress registration.
- Verify the plugin works on Ultimate Member registration.
- Review all external calls.
  - The challenge itself uses no external service.
  - GitHub is only used for update checks while the plugin is distributed from GitHub.
- Keep `readme.txt` in WordPress.org format.
- Prepare WordPress.org assets separately:
  - icon-128x128.png
  - icon-256x256.png
  - banner-772x250.png
  - banner-1544x500.png

## Submission

1. Create or use your WordPress.org account.
2. Submit the plugin ZIP on `https://wordpress.org/plugins/developers/add/`.
3. Wait for review and the final plugin slug.
4. Once approved, publish updates through the WordPress.org SVN repository.

## Important note

When the plugin is accepted on WordPress.org, you will generally want WordPress.org to handle updates natively.

At that point, review whether the GitHub updater should remain enabled or be removed in a later release.
