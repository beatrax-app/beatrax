import { emojiActionHold } from '../../../../resources/js/emoji-action-hold.js';

/**
 * Drives emojiActionHold() over a fake clock and reports what a reader would
 * have seen, so the timings measured on a device become assertable numbers.
 *
 * @link ../../../../.docs/conventions/an-icon-only-action-says-its-verb-on-touch.md
 */
let now = 0;
let pending = [];

globalThis.window = {
    setTimeout: (fn, ms) => {
        const id = Symbol('t');
        pending.push({ id, at: now + ms, fn });

        return id;
    },
    clearTimeout: (id) => {
        pending = pending.filter((t) => t.id !== id);
    },
};

const advanceTo = (t) => {
    for (;;) {
        const due = pending.filter((x) => x.at <= t).sort((a, b) => a.at - b.at)[0];
        if (due === undefined) {
            break;
        }
        pending = pending.filter((x) => x.id !== due.id);
        now = due.at;
        due.fn();
    }
    now = t;
};

const build = () => {
    const hold = emojiActionHold();
    hold.$nextTick = () => {};

    return hold;
};

const pointer = (type, x = 0, y = 0) => ({ pointerType: type, clientX: x, clientY: y });

const clickEvent = () => {
    const seen = { stopped: false, prevented: false };

    return [{
        stopImmediatePropagation: () => { seen.stopped = true; },
        preventDefault: () => { seen.prevented = true; },
    }, seen];
};

const calloutEvent = () => {
    const seen = { prevented: false };

    return [{ preventDefault: () => { seen.prevented = true; } }, seen];
};

const reset = () => { now = 0; pending = []; };

const out = {};

// The sequence measured on a Galaxy S23: the tip lands 450ms into the hold and
// Android raises its callout 130ms later, which is what used to blank it.
reset();
{
    const h = build();
    h.press(pointer('touch'));
    advanceTo(450);
    out.shownAfterHold = h.shown;

    const [ev, seen] = calloutEvent();
    advanceTo(580);
    h.callout(ev);
    out.calloutPrevented = seen.prevented;
    out.shownAfterCallout = h.shown;

    advanceTo(1497);
    out.shownWhileStillHeld = h.shown;
    h.release();
    out.shownJustAfterRelease = h.shown;
    out.swallowArmed = h.swallow;

    advanceTo(1600);
    out.shownShortlyAfterRelease = h.shown;

    const [ce, cseen] = clickEvent();
    h.guard(ce);
    out.holdClickSwallowed = cseen.stopped && cseen.prevented;

    advanceTo(4000);
    out.shownLongAfterRelease = h.shown;
}

// A short tap has to reach the action untouched, including straight after a
// hold that armed the guard and then never raised a click.
reset();
{
    const h = build();
    h.press(pointer('touch'));
    advanceTo(450);
    h.release();
    advanceTo(2000);

    h.press(pointer('touch'));
    advanceTo(5);
    h.release();
    out.tapShowsNoTip = h.shown === false;

    const [ce, cseen] = clickEvent();
    h.guard(ce);
    out.tapClickReachesAction = cseen.stopped === false && cseen.prevented === false;
}

// A mouse keeps its own title tooltip and the browser's own context menu.
reset();
{
    const h = build();
    h.press(pointer('mouse'));
    advanceTo(2000);
    out.mouseArmsNothing = h.shown === false && h.origin === null;

    const [ev, seen] = calloutEvent();
    h.callout(ev);
    out.mouseKeepsContextMenu = seen.prevented === false;
}

// A press that travels is a scroll, and must leave nothing armed behind.
reset();
{
    const h = build();
    h.press(pointer('touch', 100, 100));
    advanceTo(450);
    h.drift(pointer('touch', 100, 140));
    out.driftHidesTip = h.shown === false;

    const [ce, cseen] = clickEvent();
    h.guard(ce);
    out.driftClickReachesAction = cseen.stopped === false;
}

process.stdout.write(JSON.stringify(out));
