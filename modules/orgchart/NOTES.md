# Org chart — porting notes

## Ported (logic only)
- Chart for every login with the module; name, designation, department and branch only.
- Open positions, people on notice and leavers only for chart holders (Super Admin, HR Manager, CEO).
- Someone whose manager is hidden hangs from the first visible person above — a leaver never breaks the tree.
- My reporting line (line to the top, my reports, same-manager colleagues), whole chart with search.
- Moves refuse circles and are logged in the employee master's history.
- Replacement seats for leavers (one open replacement per leaver); positions are filled or closed, never deleted.
- Checks: no manager, manager has left, unknown manager code, reporting circles, positions under a leaver.

## Became settings / organisation data
- Reporting lines live on the employee master (`manager_code`) — one source, no second chart to reconcile.
- Holder roles → `ORG_HOLDER_ROLES`; who may open the chart → Admin → Modules.

## Not ported
- Reconciling two separate charts (a vault chart and a registers chart), newest-change-wins syncing between them,
  roll → chart auto-add and "keep off the chart" — unnecessary with one source.
- Payroll / full & final cards inside the edit window (come with those modules).

## Later
- Drag-and-drop arranging, horizontal / fit / zoom views, exit details on the chart, profile photos.
