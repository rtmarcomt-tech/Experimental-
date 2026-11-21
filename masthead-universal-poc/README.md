# Universal — website builder PoC

This folder is an isolated, dependency-free interaction prototype on the `masthead-universal-poc` branch.

## Run
Open `index.html` in a browser. No build step or external service is required.

## Implemented in this prototype
- Universal audience: personal, creator, professional, business and editorial projects.
- Three selectable visual starting points: Editorial, Studio and Portfolio.
- Live preview while editing.
- Save and reload multiple draft projects using browser localStorage.
- Reopen a saved project for editing.
- Responsive layout for desktop and mobile.

## Explicit limitations
This is a front-end interaction prototype, not the production Masthead application:
- Projects are stored only in the current browser and are not synced between devices.
- There is no registration, authentication, server-side tenant isolation or database persistence.
- There is no real publication, public subdomain, custom domain, upload pipeline or admin console.
- The preview is a single-page shell; it does not yet generate a complete multi-page website.

## Integration target
The production implementation is intended to extend the upstream Masthead Phoenix/LiveView application, preserving its site, theme, page, media and membership contexts. This folder is kept separate from the existing Laravel application in this repository and does not modify its application code.

## Next engineering stage
Port the onboarding flow into Masthead's authenticated site creation workflow, then implement tenant-scoped content editing and publication. Before production deployment, replace Fly.io-specific custom-domain provisioning with a Contabo-compatible DNS/SSL lifecycle and configure persistent S3-compatible media storage.

## Licensing gate
Verify the upstream Masthead repository's applicable licence and commercial-use terms before copying or redistributing its source. The upstream README's licence statement should be checked against the actual repository licence file and dependency licences.
