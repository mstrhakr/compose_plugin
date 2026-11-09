# Behavioral contracts

These documents recover intended behavior from implementation, tests,
documentation, and project history. They are reviewable specifications, not
automatic endorsements of whatever the implementation currently does.

## Status and authority

The initial contract is **Draft / awaiting owner review**. No behavior changes
or new enforcement are authorized by its creation.

Use these labels consistently:

- **Observed:** supported by an implementation trace; not necessarily desirable.
- **Documented intent:** promised by documentation or historical discussion;
  may differ from implementation.
- **Proposed:** a candidate requirement awaiting a decision.
- **Approved:** explicitly accepted by the project owner. Record the decision
  and date; do not infer approval from a merged implementation or passing test.
- **Verified:** accompanied by identified checks that were actually executed
  against a stated revision/environment. This is separate from approval.

Historical requests explain intent, but do not automatically become promises.
An open proposal, a superseded behavior, and a confirmed compatibility
requirement are different kinds of evidence. Contradictions remain explicit
until resolved.

## Contract index

| ID | Boundary | Status |
| --- | --- | --- |
| BOU | [Rebuild-on-update preference](build-on-update.md): storage, editor round trip, resolution, and update-command handoff | Draft / awaiting owner review |

The [source register](sources.md) records research coverage, relevant historical
evidence, and limitations. Other capabilities have not yet been specified.

## Reviewing the pilot

1. Read the plain-language purpose and decision table.
2. Decide the open questions before treating the proposed rules as requirements.
3. Accept or amend individual requirement IDs, not just the document as a whole.
4. Record any intentionally retained differences between entry points.
5. Link each approved rule to appropriate enforcement and regression cases.

Implementation changes should identify affected contract IDs. Behavior changes
should update the approved contract and its enforcement together; discrepancies
should not be silently resolved by editing the contract to match the code.
This is a proposed working practice, not a new CI gate.

## Keeping contracts useful

- Specify capabilities and shared boundaries rather than duplicating a document
  for every source file.
- Keep policy ownership, execution, persistence, and presentation distinct.
- Cover errors, forbidden side effects, concurrency, and compatibility, not just
  successful inputs and outputs.
- Prefer outcome tests over tests asserting that a wrapper calls another wrapper.
- Distinguish tests found by inspection from tests run, and simulated checks
  from live-host guarantees.
- Keep historical evidence in the source register; keep the behavior definition
  in the contract. Do not copy entire issue or forum conversations into docs.

Documentation-only contract recovery requires no code test run. Implementing
approved contracts must follow the repository's existing validation process in
the [development guide](../development.md) and repository guidelines.
