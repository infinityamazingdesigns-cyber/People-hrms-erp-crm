# Biometric machines — porting notes

## Ported
- **Sources**: a machine (make, model, branch) or a piece of software. **Machine direct** = the ADMS / "Cloud server"
  push protocol of ZKTeco, eSSL, Realtime, Identix, BioMax and most ZK-based machines, accepted only for a registered
  serial number (unknown serial numbers are listed under "Machines asking to connect" with *Add this machine*).
  **API key** = vendor software (Matrix COSEC, Suprema BioStar, Hikvision …) or a script; the key is shown once, kept only
  as a SHA-256 hash, and *New key* stops the old one at once. Optional allowed addresses (IPv4 / IPv6, ranges). Pause with
  a reason; punches already in stay.
- **Only attendance lines are read** (ADMS table ATTLOG); templates, user lists, photos and logs are answered OK and thrown
  away; no command is ever sent to a machine. `/iclock/…` is the one path exempt from the HTTPS redirect (old machines
  speak plain HTTP).
- **Times** in the organisation's time zone, ISO 8601 with an offset, day-first or Unix seconds; older than 62 days or more
  than 10 minutes ahead refused with the reason; a person + time is kept once.
- **Codes**: "machine ID = employee code" lines per source, else a prefix + ID; codes that match nobody this month listed.
- **Attendance register**: machine punches join the phone punches — the earliest of the day is the check-in, the latest the
  check-out; machine times show as "machine" with its name.
- Public endpoints throttled by address and noindex; only the Super Admin changes sources (HR Manager reads).

## Became settings
- Time zone from the organisation (the reference build assumed one country's time).

## Left out
- The reference's eTimeOffice cloud pull (one vendor's account) and its uploaded In-Out report import; a per-machine push token.
