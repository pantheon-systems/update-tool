# update-tool test suite — access audit (SITE-5866)

> Working note, do not commit. 2026-06-16.

## Repos touched (all in `pantheon-fixtures`)

| Repo | Role | Access |
|---|---|---|
| `drops-8-fixture` | Drupal-8 update target (clone, force-push, PR) | contents:write + PR:write |
| `drupal-8-fixture` | Drupal-8 source (clone) | read |
| `pantheon-wp-fixture` | WP update target + derivative source (clone, push, PR) | contents:write + PR:write |
| `wordpress-network-fixture-{seed}` | derivative target, **created + deleted at runtime** | org repo create + delete |
| `cos-runtime-php-fixture` | referenced only (no active test) | none |
| `drops-8-fork-{seed}` | dead path (`if(false)`) | none |
| `WordPress/WordPress` (public) | version source, not cloned | none |

## Min token access (on pantheon-fixtures)

- Contents: read+write
- Pull requests: read+write
- Administration: read+write (repo create + delete)
- Fine-grained PAT → owner pantheon-fixtures, **All repositories** (seed repo not pre-existing). Classic fallback: `repo` + `delete_repo`, SSO-authorized.
