# Human Card Check Pro Architecture

## Product split

### Free plugin

- card challenge
- multi-language challenge UI
- WordPress registration support
- Ultimate Member registration support
- GitHub updates for self-hosted distribution

### Pro addon

- trust score
- email domain analysis
- disposable email checks
- registration risk logs
- configurable thresholds
- actions by score:
  - allow
  - require extra verification
  - moderate
  - block

## Activation model

1. Customer pays from a checkout link.
2. Payment flow returns or emails a token.
3. Site owner pastes the token in `Settings > Human Card Check`.
4. Free plugin stores token status.
5. Pro addon uses the token state to unlock premium analysis.

## Recommended implementation

- Keep the free plugin public and self-contained.
- Ship premium logic in a separate addon plugin:
  - `human-card-check-pro`
- Let the addon hook into:
  - `human_card_check_pro_analyze_registration`

## Token validation options

### Simple start

- token generated manually or by checkout automation
- local format validation only
- used to unlock features on trusted sites

### Better version

- token validated against your API
- API returns:
  - active / inactive
  - plan
  - expiry date
  - allowed sites

## Notes for WordPress.org

- Keep payment and license activation out of the core free experience as much as possible.
- Do not make the free plugin useless without the Pro token.
- Be transparent about any external API calls used for license validation.
