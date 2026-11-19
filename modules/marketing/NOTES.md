# Marketing & ROAS — porting notes

## Ported
- Campaigns with their own code (MK0001 …), channel (generic list: social pages, messaging, email, SMS, social / search /
  display ads, listings, maps, website, referral, exhibition, print, other), objective, status, branch (none = every
  branch), owner, dates, budget, landing page, UTM source / medium / campaign (filled from the channel and the name),
  platform campaign ids and other names it appears under, notes. Archived instead of deleted.
- Tracked link = landing page + UTM + the code as `utm_id`; a chat link with the code in the first message when a chat
  number is set.
- A lead or spend line finds its campaign by code in any text → platform id → UTM campaign → name / listed name.
  Linking an unmatched lead by hand remembers its name on the campaign for next time.
- Spend per campaign per day: typed (any cost), or a platform's campaign-by-day report (CSV / .xlsx) read by column
  titles; ad-set rows add up per day; other currencies left out and counted; month-first dates ticked. One report line per
  campaign + day: a changed figure replaces it and the old line stays switched off with the reason. Check writes nothing.
- Leads: typed by staff, a lead file (columns by title), or the public lead hook (`modules/marketing/lead.php`) with a key
  shown once and kept only as a fingerprint; a new key stops the old; throttled per address; the form's own id makes a
  repeat a no-op. Number / mail / name / campaign only — never message text.
- A new lead becomes an enquiry (source from the campaign channel, else the lead kind, else Other; follow-up as the
  register's setting) or a note on the open enquiry with the same number or mail; switchable.
- Consent per number, yes / no with how the customer told us, append-only, newest answer counts; "yes" list as CSV.
- Orders → campaigns: a hand pick (also "no campaign") always wins; otherwise the customer's latest lead with a campaign
  in the window (90 days) before the order, set once. Drafts and cancelled orders never count.
- ROAS = order value before tax ÷ spend (one currency); cost per lead, cost per order, click-through rate, share of order
  value campaigns explain; per campaign and in total; CSV.
- Content plan: posts by date, channel, campaign, status, owner, link once published.
- Rights from settings: full (every campaign in branch scope, hook key, consent list), own (their campaigns, spend,
  leads, posts), read (campaigns, ROAS, leads, consent). Change log `mk_log`; nothing is deleted.

## Became a setting
- Who has full / own / read rights; attribution window; currency; hook calls per 15 minutes; chat number; whether leads
  reach the enquiry register.

## Left out
- Platform API pulls (Meta Insights, lead forms webhook), the messaging provider's webhook and contact sync, STOP replies
  by webhook, lead-form answers, media from chats, delivery funnel. Each needs its own connection set-up per organisation.
