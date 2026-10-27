# ICRC application identity

The supplied `storage/app/public/Images/ICRC_Logo.png` and `BREVITY_logo.png` are published unchanged to `public/brand/` so they work without a Windows storage symlink. The official ICRC asset is the default logo in the shared application header, landing page and footer, and is used for the favicon and Apple touch icon. A subsequently uploaded logo can still override the displayed identity through programme settings; the favicon retains the supplied ICRC asset.

The green, gold and white palette is drawn from the supplied ICRC logo. `public/ceremony.css` extends the existing application styles, including dashboard, account and management pages. `public/brand/infrastructure.svg` and `contours.svg` are original decorative illustrations, not official infrastructure photographs or additional logos.

The landing page includes the programme countdown, six-week explorer, learning themes, confirmed milestones, participant guidance, FAQs and support. All six chapters remain readable without JavaScript. The countdown timestamps include the programme's configured timezone offset. Week navigation supports arrow keys, Home and End. FAQ disclosure controls are native HTML. The mobile navigation toggle supports Escape and returns focus. Motion respects `prefers-reduced-motion`.

The footer includes programme navigation, participant access, configured support information, dates, privacy link when configured, and Brevity Anderson event producer credit. No contact address, venue, social account or other official detail has been invented. The requested reference site could not be retrieved in this environment; the footer is an original design based on the requested scope.

When replacing the source PNGs, copy the approved replacements to the corresponding `public/brand/` files as well. Public branding files are intentionally public; learning resources remain private.

Validation: run `node --test tests/Frontend/programme.test.cjs` for the interactive behaviour and `php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml` for application and access tests against the isolated MySQL/MariaDB test database.
