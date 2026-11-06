### Changelog

Each package under `src/` has a `CHANGELOG.md`. If your change affects a package's users, add an entry under
`Unreleased`. Use the `Breaking changes`, `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` or `Security` heading,
and end the entry with a link to your pull request. Say what changed for the user and what they need to do, if
anything. Put anything that can break existing code or configuration under `Breaking changes`, including a raised PHP
or dependency minimum.

A change that touches several packages gets an entry in each one it affects. Tests, CI, tooling and internal
refactoring don't need an entry.

The headings decide the next release. `Fixed` or `Security` alone make a patch release. Any other heading makes a
minor release.

A check flags a pull request that changes a package without changing its changelog. If no entry is needed, a
maintainer adds the `skip-changelog` label. To run the check locally, use `script/check_changelog.sh`.

### Maintainers

- [Bob Strecansky](https://github.com/bobstrecansky), Intuit
- [Brett McBride](https://github.com/brettmc), Deakin University

For more information about the maintainer role, see the [community repository](https://github.com/open-telemetry/community/blob/main/guides/contributor/membership.md#maintainer).

### Approvers

- [Ago Allikmaa](https://github.com/agoallikmaa)
- [Cedriz Ziel](https://github.com/cedricziel)
- [Chris Lightfoot-Wild](https://github.com/ChrisLightfootWild)

For more information about the approver role, see the [community repository](https://github.com/open-telemetry/community/blob/main/guides/contributor/membership.md#approver).

### Triagers


For more information about the triager role, see the [community repository](https://github.com/open-telemetry/community/blob/main/guides/contributor/membership.md#triager).

Find more information about the triager role in the [community repository](https://github.com/open-telemetry/community/blob/master/community-membership.md#triager)
