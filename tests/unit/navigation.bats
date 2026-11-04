#!/usr/bin/env bats

load '../bats_setup.bash'

@test "action modal close refreshes without navigation or cancelling completion tracking" {
    run node "$BATS_TEST_DIRNAME/../fixtures/action-modal-ui.cjs"
    [ "$status" -eq 0 ]
}
