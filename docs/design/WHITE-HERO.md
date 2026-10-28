# White orientation hero asset

Built-in image_gen generated the portrait at public/brand/orientation-host-v1.png (1024 x 1536 PNG with alpha). The original generated asset remains in the Codex generated_images folder. No biometric or real staff portrait was used.

Final generation prompt:
Create a photorealistic professional website hero cutout portrait of a Nigerian Black woman aged approximately 28�35, dark brown skin, natural black hair in a neat high curly puff, warm confident smile looking directly at camera. White crisp long-sleeved button-down shirt tucked into tailored charcoal grey trousers. Show from above the complete hair to upper thighs. Standing front-facing, her left forearm folded horizontally across her waist supporting her right elbow; her right arm bent across her chest and right index finger pointing clearly toward image LEFT, towards website headline. Natural anatomically correct hands. Elegant public-service professional appearance, soft premium studio lighting, natural skin texture, clean white shirt detail. Isolated on genuinely transparent background, no graphics, no circles, no text, no logo, no watermark, no props. Portrait aspect ratio with full hair and elbows inside frame. This is a new website asset, reference composition described only.

The existing landing-page copy and official transparent logo are retained. The hero uses white and the existing ICRC green; soft circular layers and orientation cards are CSS/HTML. Landing-page-specific top bar styling retains all institutional text and navigation. Countdown follows the hero. Desktop and narrow layouts were reviewed in Chrome; Blade compilation passed. No attendance, database or retention changes belong to this design update.

## Colleague portrait and header contrast update

The current hero uses public/brand/orientation-colleagues-v2.png, a 1254 x 1254 transparent PNG created using the built-in image_gen edit tool. Original portrait retained as orientation-host-v1.png.

Prompt: Preserve the existing Nigerian woman's identity, high puff hairstyle, white shirt, smile and left-pointing pose. Add a smiling Nigerian male colleague slightly behind her at image right, wearing a deep green blazer and ivory open-collar shirt, arms naturally folded. Frame both from complete hair to upper thighs with anatomically correct hands, cohesive studio lighting and a genuinely transparent background. No text, logos or additional people.

The institutional top bar is now deep ICRC green with a restrained gold edge and light text. The menu bar is pale sage, and the hero stays white. Programme content, links and supplied logos remain intact. Blade compilation passed. The new portrait was inspected and its transparent alpha verified. Browser review of this revision could not be completed: Computer Use stopped because it could not confidently determine the active browser URL for policy enforcement. No further browser automation was attempted.

## Manrope typography and restrained hero animation

Manrope variable font (weights 200-800) is self-hosted at public/fonts/manrope/Manrope-variable.ttf, with its SIL Open Font License alongside it. Source: https://github.com/google/fonts/tree/main/ofl/manrope. Shared typography.css is loaded and the font preloaded by layout.blade.php, so public and authenticated screens, headings, controls, tables and printable browser reports use 'Manrope', system-ui, sans-serif. Server-generated PDF documents retain their separate PDF font configuration.

Hero animation is limited to a soft copy entrance, a slightly delayed portrait entrance, a four-pixel caption float and small button-arrow hover movements. The existing circle design is unchanged. Reduced-motion preference disables this motion. Blade compilation and HTTP checks for the landing page, stylesheets and font asset passed. No new browser visual review was performed.

## Welcome to the Commission section

White background with a low-opacity infrastructure line graphic (public/brand/orientation-foundations.svg). Replaced static principle tiles with three keyboard-accessible native details/summary cards, preserving their original headings and descriptions. Each expands to explain a foundation and links to its relevant programme week using the existing week explorer. The first card starts open; the shared details name enables exclusive expansion in supporting browsers, with a usable multi-open fallback. Hover/focus states and restrained expansion feedback are included. No added JavaScript dependency. Blade compilation and HTTP/content checks passed; browser visual inspection was not performed.
The infrastructure watermark was subsequently removed from the Welcome section and replaced with oversized, lightly outlined PURPOSE lettering, angled subtly along the lower background. White background and interactive themes retained.
The PURPOSE lettering was replaced with the supplied transparent ICRC logo as a faint watermark across the left half of the Welcome section, with a contained upper-section treatment on mobile. Interactive content and white background remain.

## More than an introduction

Changed the experience section to white, removed all three SVG icons from its markup and adjusted tablet/mobile layouts to remove the old icon columns. Three typography-led cards retain existing copy and programme links. Existing IntersectionObserver supplies a staggered scroll reveal; green accent lines animate on entry, with a six-pixel lift and arrow movement on hover/focus. Reduced-motion settings suppress animations. Blade compilation and live HTTP/markup checks passed. No new browser visual inspection was performed.

## Dates timeline animation

Added timeline-motion.js to progressively draw the green/gold timeline as the user scrolls through its four existing milestones. Existing viewport reveals now slide milestones in with a short stagger and a one-time dot highlight; dates lift subtly on hover. Work is throttled with requestAnimationFrame and suspended outside the viewport, with passive scroll listeners. Reduced-motion and no-JavaScript modes retain a readable static timeline. Programme dates and text were preserved. JavaScript syntax, Blade compilation and HTTP delivery checks passed; no browser visual review was performed.

## Arrive informed / Before you begin

Set the section background to #FCFDFB and removed the contour pseudo-element. Changed supporting text to readable green/grey tones. The white checklist panel has a gentle eight-pixel floating/shadow animation after entering view, plus staggered step entrances. Hover pauses the panel, and reduced-motion settings disable the effects. Existing copy and dashboard link remain. Blade compilation and HTTP checks passed; no browser visual review was performed.

## Connected Support graphic

Added an HTML/CSS/SVG support network on the right of Here for your next step, based on the supplied reference: soft white semicircular surfaces, fine connecting paths, four raised circular nodes and a sculpted green Support pill. Nodes show WhatsApp, Email, Facebook and Instagram. Existing #FCFDFB section background retained. Icons reveal channel labels on hover and float gently after the network enters view; reduced-motion preferences disable movement. This is a visual illustration; no unprovided contact addresses or social handles were invented. The removed producer block was not restored. Blade compilation and live HTTP/content checks passed; browser visual review was not performed.

## Reference honeycomb support design

Replaced the circular network with ten rounded, raised hexagon tiles arranged 3 / 4 / 3, matching the supplied reference composition. The four highlighted positions retain the existing WhatsApp (downloaded original SVG), Email, Facebook and Instagram icons. Decorative cells remain muted. Shapes are native SVG/CSS, with quiet entrance and hover relief rather than moving connector lines. The section background and shared column alignment with the footer button remain unchanged. Blade compilation and HTTP/content checks passed; browser visual review was not performed.

## Cinematic hero portrait entrance

The male/female portrait now rises and settles with a scale-in, soft-to-sharp focus, brief reveal mask and understated backlight. The existing halo and caption enter in coordination. portrait-entrance.js waits for image decoding and first viewport entry before starting, including mobile where the portrait is below the first screen. It runs once and respects reduced-motion settings. No image regeneration or hero layout changes. JavaScript syntax, image-ready/viewport gating checks, Blade compilation and live asset delivery passed; browser visual review was not performed.

## Hero headline premiere

Wrapped the existing ICRC 2026 / Staff Orientation / Programme heading in three masked line spans. Each rises into view with a brief focus-to-sharp effect and short stagger, then settles without looping. The existing entrance script now waits for Manrope font readiness and heading visibility separately from portrait readiness, preserving mobile behavior. Reduced-motion and no-JavaScript modes keep the full headline readable. JavaScript syntax, font/viewport gating, Blade compilation and HTTP markup checks passed; browser visual review was not performed.

## Coordinated whole-hero entrance

Unified the opening into a shared scene: atmosphere, programme kicker, welcome, three headline lines, description, dashboard links, date line, optional venue, portrait/backlight and caption. Stagger completes in roughly 2.5 seconds, then all text and portraits settle. Existing subtle ambient drift remains. Assets/fonts are allowed up to 1.5 seconds before text proceeds; mobile portrait still waits for image decoding and actual viewport entry. Reduced-motion and no-JavaScript fallbacks remain readable. JavaScript syntax, scene/asset/mobile visibility gating, Blade compilation and HTTP delivery checks passed; browser visual review was not performed.

## ICRC logo preloader

Added preloader.css and preloader.js to the shared layout. The supplied ICRC logo breathes/pulses on an off-white loading screen. The overlay fades away after page readiness with a short 650 ms minimum display; a four-second timeout prevents a stalled resource from trapping the page. Interaction beneath the overlay is temporarily inert and restored on dismissal. No-JavaScript mode does not show the loader; reduced-motion mode shows a static logo and removes the fade. The hero scene waits for icrcPageReady so its entrance is visible after dismissal. Back/forward-cache restoration dismisses any stale overlay. JavaScript syntax, normal/timeout interaction-release checks, Blade compilation and live markup checks passed; no browser visual review was performed.

## Director General welcome section

Added resources/views/partials/dg-welcome.blade.php immediately after the programme countdown and before Welcome to the Commission. Uses the supplied storage/app/public/Images/icrc_dg.png without alteration, copied to public/brand/icrc-dg-orientation-v1.png for reliable public delivery. Portrait and name/title appear on the left on desktop; the formal address is on the right, stacked responsively on mobile. Section background is white, with a restrained portrait frame, green/gold typography details and staggered image/caption/paragraph entrances. Reduced-motion mode remains static.

The welcome address is newly drafted for the user's review and has not been represented as a previously published or approved DG statement. Name/title confirmed against the official ICRC profile: https://www.icrc.gov.ng/profile-of-the-director-general/. No signature image, quotation attribution or endorsement claim was fabricated. Blade compilation, live section ordering, portrait delivery and content checks passed; browser visual review was not performed.

## DG welcome editorial refinement - 28 October 2026

Moved the heading into a full-width header row so Welcome to a new chapter of service. appears on one line on desktop. Narrow phones retain natural wrapping for readability. Refined the portrait into a formal framed image with a deep-green nameplate, reduced unnecessary framing, and improved article spacing and typographic hierarchy. Corrected two U+FFFD replacement characters in Nigeria's and Commission's by rewriting the partial as UTF-8 through Node rather than passing smart apostrophes through the shell's default file encoding. Blade compilation and live markup checks passed. Reviewed in Chrome desktop: single-row title, portrait/nameplate and repaired text confirmed.
