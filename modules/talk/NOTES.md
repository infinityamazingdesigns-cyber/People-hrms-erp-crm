# Team chat — porting notes

## Ported
- Direct chats and groups; the chat list with unread counts; messages refresh every few seconds while the page is in
  view (works without scripts too). Enter sends, Shift + Enter for a new line.
- Nobody reads a chat they are not in — admins included: "All chats" shows names, members and counts only.
- Policy: direct messages two-way / employees with leads only / leads start and employees reply; who creates groups;
  files on or off; read receipts on or off. Directory shows designations to leads only.
- Groups two-way or one-way (only admins send); admins add / remove members, make / drop admins, change name,
  description and mode; the last admin cannot leave while others remain; system lines record each change.
- Only admins remove messages (a group's admins in their group, the manage roles in chats they are in), never the
  sender; files go with the message.
- Ticks for the sender: sent ✓, delivered to everyone ✓✓, read by everyone (blue) — counted over members who were in the
  chat when it went; delivered only when receipts are off.
- Files by their own bytes (photos, PDF, Word / Excel / PowerPoint as real zips, clean text / CSV), 10 MB, up to 6 a
  message, in the database, opened only by current members (sandboxed).
- Whole chats deleted only by the delete roles with a reason, into an append-only log of names and counts (never text).

## Became settings
- The policy above and the lead / manage / delete roles.

## Not ported (later)
- Group pictures and icons, stickers / GIFs, emoji picker, formatting, message info (who read when), mark all read,
  browser push, the crane word of the day panel.
