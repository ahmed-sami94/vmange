# Installation

VMange can run from a WordPress subfolder, public subfolder, subdomain, standalone PHP host, Docker container, or local VM.

- Upload the contents of `vbox.zip` directly into the target folder. The archive root must contain `index.php`, not a nested `vbox/` directory.
- Open `index.php` and run the installer when no config exists.
- Keep HTTPS enabled for public deployments.
- Keep `install.lock` after setup so the installer is not exposed.
- Use the Settings page for users, base URL, and operational options.

For WordPress hosting, use direct PHP file routing and avoid changing global WordPress rewrite rules.
