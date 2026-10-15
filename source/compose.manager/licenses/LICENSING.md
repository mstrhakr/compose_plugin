# Licensing and fork provenance audit

Audit date: 2026-10-12. This is a technical provenance assessment, not legal
advice or a determination of copyright ownership.

## License status

[The project license](PROJECT-LICENSE.txt) grants MIT permissions only for original
material owned and licensable by Nick Szittai (mstrhakr). It does **not** license
inherited upstream material, independently owned contributions, or third-party
components. The same scoped text is shipped as [PROJECT-LICENSE.txt](PROJECT-LICENSE.txt).

MIT permits commercial use, modification, redistribution, and sublicensing,
without a requirement to publish source. Its copyright and permission notice
must be retained. It cannot grant rights the licensor does not own.

**The complete plugin is not yet established to be MIT-licensed or cleared for
unrestricted redistribution.** Permission for surviving upstream material and
other independently owned material remains unresolved. This applies even where
new MIT-covered changes share a file with inherited code.

## Compared revisions

- Fork `dev` HEAD before this licensing change:
  `99a17fc6c8cdd6761463ba049f5dac5615a32a77`.
- Original upstream `dcflachs/compose_plugin` main:
  `074a9af3a29d14b346e5dae8a700a0649cda601c`.
- The merge base is the same upstream commit: the complete upstream tip is an
  ancestor of the audited fork HEAD. Its commit date is 2025-11-08 UTC.
- Upstream repository HEAD was checked against GitHub on the audit date.
- The local repository is not shallow; upstream ancestry contains 147 commits,
  beginning with dcflachs's 2021-10-01 initial commit, `4cfb0fa`.

References: [original repository](https://github.com/dcflachs/compose_plugin),
[audited upstream commit](https://github.com/dcflachs/compose_plugin/commit/074a9af3a29d14b346e5dae8a700a0649cda601c),
[audited fork commit](https://github.com/mstrhakr/compose_plugin/commit/99a17fc6c8cdd6761463ba049f5dac5615a32a77).

## How much upstream material remains?

**Yes, inherited implementation remains, not just names or configuration.**

Git line attribution finds **518 upstream-attributed lines in 18 files**, out
of **37,901 lines in 69 application text files: 1.37%**. Those totals exclude
the two known vendored JavaScript libraries and PNG assets. This is a measure
of surviving Git attribution, **not a percentage of legal copyright ownership
or proof that the other 98.63% is independent work**. Edited/adapted upstream
code can retain copyright significance without retaining its blame attribution.

The upstream application-text baseline contains 2,147 lines in 21 files, with
the same vendor exclusions. The fork is much larger; a small fraction of its
current size does not eliminate upstream licensing requirements.

### Surviving application line attribution

Counts include blank lines, comments, markup, and configuration, not just
executable statements. File paths refer to the audited fork revision.

| Current file | Upstream-attributed lines | Total lines |
| --- | ---: | ---: |
| [Compose.page](../Compose.page) | 11 | 22 |
| [compose.manager.page](../compose.manager.page) | 5 | 34 |
| [compose.manager.settings.page](../compose.manager.settings.page) | 76 | 3,478 |
| [default.cfg](../default.cfg) | 4 | 35 |
| [event/docker_started](../event/docker_started) | 21 | 354 |
| [event/stopping_docker](../event/stopping_docker) | 14 | 122 |
| [include/ComposeList.php](../include/ComposeList.php) | 2 | 486 |
| [include/ComposeManager.php](../include/ComposeManager.php) | 18 | 855 |
| [include/ComposeUtil.php](../include/ComposeUtil.php) | 14 | 154 |
| [include/Defines.php](../include/Defines.php) | 16 | 68 |
| [include/Exec.php](../include/Exec.php) | 159 | 2,690 |
| [include/Helpers.php](../include/Helpers.php) | 23 | 615 |
| [include/ShowTtyd.php](../include/ShowTtyd.php) | 6 | 203 |
| [include/Util.php](../include/Util.php) | 2 | 4,543 |
| [javascript/common.js](../javascript/common.js) | 2 | 253 |
| [javascript/composeManagerMain.js](../javascript/composeManagerMain.js) | 27 | 9,362 |
| [scripts/arrayNotStarted.sh](../scripts/arrayNotStarted.sh) | 3 | 6 |
| [scripts/compose.sh](../scripts/compose.sh) | 115 | 779 |

Concrete remnants include compose shell option/environment handling, PHP action
dispatch and stack metadata operations, plugin configuration setup, array/Docker
event setup, settings-page integration, and UI helpers. Several former `php/`
files were renamed and refactored into `include/`; moving them is not a rewrite.

Installer/build attribution, **outside the application totals above**:

| File | Upstream-attributed lines | Total lines |
| --- | ---: | ---: |
| [compose.manager.plg](../../../compose.manager.plg) | 51 | 134 |
| [build_in_docker.sh](../../../build_in_docker.sh) | 1 | 20 |
| [source/pkg_build.sh](../../pkg_build.sh) | 15 | 248 |
| [.github/workflows/build.yml](../../../.github/workflows/build.yml) | 26 | 293 |

The only current tracked file whose entire Git blob is identical to an upstream
tracked blob is [js-yaml.min.js](../javascript/js-yaml/js-yaml.min.js). This is
third-party MIT code, not evidence of MIT permission for dcflachs's plugin.
There are **no wholly byte-identical upstream first-party files**, but the
line-level remnants above demonstrate that whole-file replacement is not the
right test for inherited code.

### Reproducing the measurements

Use the fixed revisions above, not a later working-tree snapshot. Enumerate
tracked files under `source/compose.manager/` at the audited fork commit with
`git ls-tree -r --name-only`. Exclude paths containing `/javascript/js-yaml/`,
`/javascript/qrcode/`, and paths ending in `.png`.

For each remaining path, inspect `git blame --line-porcelain -w -M -C -C` at
the audited fork commit. Count each content record (a line starting with a tab)
once. Classify it as upstream-attributed if its associated commit SHA is in
`git rev-list` of the audited upstream commit. Sum per-file counts. The flags
ignore whitespace changes and attempt to follow moved/copied lines; rename
following is also provided by Git blame. For identical-file results, compare
blob IDs from each revision's recursive `git ls-tree` output.

This method can miss heavily rewritten derivations and can count trivial shared
boilerplate. It is evidence for review, not an exhaustive originality analysis.
New audit/license documents added by this change are not in the measured HEAD.

## Original upstream permissions

No project LICENSE, COPYING, or NOTICE grant was found in the upstream tip or
historical tracked filenames. Historical upstream README versions examined did
not supply copying, modification, redistribution, or relicensing permission.
GitHub's repository API reported `license: null`; its license endpoint returned
404. These API results support, but do not replace, the source/history review.

A public GitHub repository and permission to fork on GitHub do not establish a
general open-source license. The fork's former README claim that it was "open
source" was not a license grant. The existing MIT metadata in
[composer.json](../../../composer.json) names the fork's tests project; it does
not establish upstream permission for inherited plugin code.

Upstream history records dcflachs, Emiel de Jong, Christoph Hummer, Enkows Zhang,
Eric Nemchik, Leandro Zazzi, and mtongnz. Commit attribution is not proof of legal
ownership. dcflachs's permission alone may not resolve independently owned
contributions; determine which owners' material survives and what authority or
permissions cover it. The same principle applies to other contributors to this
fork: this license does not silently relicense their past work.

## Third-party and historical material

[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) includes complete direct-component
license texts for js-yaml 4.1.0 (MIT), qrcode-generator 1.4.4 (MIT), Docker
Compose 5.5.0 (Apache-2.0 plus NOTICE), resvg 0.48.1 (MIT option), and Ace builds
1.43.5 (BSD-3-Clause). These permissive licenses do not clear original plugin
rights. Notices are version-specific and need review when versions change.

The build recursively copies this source subtree, so the scoped project license,
this audit, and the direct-component notices are included in new packages
without changing the package scripts. Existing releases are not retroactively
repackaged or cleared by this change.

Additional issues:

- [question.png](../images/question.png) is byte-identical to the
  [Unraid WebUI image](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix.docker.manager/images/question.png).
  SHA-256: `85b3ab5476c3cdcdfac6f1b31a0da98fe582fff692b05ba597594cf49b6e721b`.
  No image-specific license grant was established. Obtain permission or replace
  it with an independently created/licensed asset; do not assume a neighboring
  source file's license covers it.
- Documentation screenshots and artwork need their own provenance/rights review,
  especially depicted third-party UI, logos, and icons. This MIT grant conveys
  no third-party trademark rights.
- Legacy Docker WebUI patches were removed from current HEAD by `ff9cb58`.
  Their original DockerClient target has an explicit GPL version 2 grant, with
  Lime Technology and other copyright holders. Historical DockerContainers
  targets also carry GPL-v2 grants. Distribution of derivatives may trigger
  GPL obligations for those derivatives; this does not by itself prove the
  whole independent plugin must be GPL. Older releases need separate review.
  [Verified historical target](https://github.com/unraid/webgui/blob/c2478b16bd5b8261ed8aca040f58679b2d06de5c/plugins/dynamix.docker.manager/include/DockerClient.php).
- Historical Compose Switch, Ace, and SweetAlert bundles have separate licenses.
  Older releases must be assessed against their actual included components.
- Docker Compose/resvg binaries contain dependencies beyond their top-level
  licenses. Their transitive notices and source/distribution obligations have
  not been fully audited. A top-level notice file is not proof of full compliance.
- Host-provided ttyd, PHP, Unraid libraries, and UI assets are not relicensed by
  the plugin. Their actual versions and use/distribution require separate review.

## Permission checklist before claiming a complete open-source license

1. Ask dcflachs for an explicit written MIT grant covering the inherited plugin
   material, identifying the repository and commit/revisions. Prefer an upstream
   license commit or a permission record that can be preserved with this project.
2. Clarify authority over other upstream contributions and obtain any additional
   required rights-holder permissions. Do the same for independently owned fork
   contributions; do not infer retroactive consent from this PR.
3. Clear or replace the copied question icon; review other asset provenance.
4. Finish binary transitive-dependency notices and historical GPL/distribution
   review as applicable. Preserve existing third-party copyright/license notices.
5. Once permissions are established, update the scope and copyright attribution
   with the actual evidence. Keep this unresolved status explicit until then.

If permission cannot be obtained, independent replacement of affected material
is an alternative to investigate. Merely changing filenames, formatting, most
lines, or the license file does not establish an independent implementation.
