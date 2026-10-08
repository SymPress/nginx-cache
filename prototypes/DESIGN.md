# Cache metrics design contract

The dashboard answers two questions: how much eligible traffic Nginx serves
from cache, and whether local cache operations need attention. The supplied
screenshots are the reference for the existing blue accent, white surfaces,
compact controls and sidebar. The open paragraph block in the latest screenshot
has weak hierarchy and repeats information already present in the KPI cards.

- Put the hit rate, request denominator and proportional distribution together
  in one performance panel. Keep files, size, queue and tags in four compact
  cards beside it. Keep the existing purge and dry-run actions below.
- Show cache hits and newly requested responses as two labeled totals. These
  are groups of statuses, not synonyms for HIT and MISS. Use text as well as
  color; do not infer a trend or a health score from nine requests.
- Show a small-sample note only below 100 requests. A bounded log sample gets
  its own label. Include the measured time and explicitly explain that a page
  reload updates the snapshot.
- Keep the six exact Nginx status counts and counting rules in a native,
  initially closed details disclosure. Opening it must not submit the form.
- Empty traffic, missing log and unreadable log have distinct explanations.
  Unavailable data uses a dash and no colored distribution, never a fake 0%.
- At desktop widths use a performance panel and a two-by-two operational grid.
  Below 1100px stack them; below 480px stack operational cards. Text and controls
  wrap without horizontal document overflow at 320, 768, 1024 and 1440px.
- Preserve WordPress theme colors, existing controls and page navigation.
  Reject a duplicate hit-rate tile, an always-open prose block, decorative
  charts without data, and extra libraries for a two-segment distribution.

The standalone HTML prototype uses the screenshot's 8/9 request snapshot.
Its state selector is a preview control, not a production setting. Production
renders the same component from the diagnostics snapshot.
