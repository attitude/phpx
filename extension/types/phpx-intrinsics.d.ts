/// <reference lib="dom" />

/**
 * PHPX JSX type declarations — no React required.
 *
 * Provides HTML attribute types for the /* @jsx h *\/ pragma.
 * Uses TypeScript's built-in DOM types from lib.dom.d.ts.
 *
 * Usage in a project tsconfig.json:
 *   "jsx": "react",
 *   "jsxFactory": "h",
 *   "types": ["path/to/phpx-intrinsics"]   // or /// <reference path="..." />
 */

// ─── JSX factory (no-op signature — only the types matter for PHPX) ───────────

declare function h(
    type: string | ((props: Record<string, unknown>) => unknown),
    props: Record<string, unknown> | null,
    ...children: unknown[]
): unknown;

declare namespace h {
    namespace JSX {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        type Element = any;
        interface IntrinsicElements extends PHPXIntrinsicElements {}
    }
}

// Global JSX namespace — TypeScript checks this for `.tsx` JSX type safety
declare global {
    namespace JSX {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        type Element = any;
        interface IntrinsicElements extends PHPXIntrinsicElements {}
    }
}

// ─── Base attribute helpers ────────────────────────────────────────────────────

type PHPXEventHandler<E extends Event = Event> = (event: E) => void;

/** CSS styles as a key-value map (PHPX convention) */
type PHPXStyle = Record<string, string | number>;

// ─── Common HTML attributes shared by all elements ────────────────────────────

interface PHPXHTMLAttributes {
    // Core
    id?: string;
    /** Maps to the HTML `class` attribute */
    className?: string;
    title?: string;
    lang?: string;
    dir?: 'ltr' | 'rtl' | 'auto';
    hidden?: boolean;

    // Inline style as a key/value map (PHPX compiles to HTML style string)
    style?: PHPXStyle;

    // Interactivity
    /** Maps to the HTML `tabindex` attribute */
    tabIndex?: number;
    contentEditable?: boolean | 'inherit' | 'plaintext-only';
    draggable?: boolean;
    spellCheck?: boolean;
    translate?: 'yes' | 'no';
    inert?: boolean;
    popover?: 'auto' | 'manual' | '';

    // Accessibility
    role?: string;
    slot?: string;
    part?: string;
    exportparts?: string;
    is?: string;
    nonce?: string;

    // PHPX-specific
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    children?: any;
    key?: string | number | null;
    dangerouslySetInnerHTML?: { __html: string };

    // ─── Event handlers (JSX/PHPX camelCase names) ───────────────────────────

    // Mouse
    onClick?: PHPXEventHandler<MouseEvent>;
    onDoubleClick?: PHPXEventHandler<MouseEvent>;
    onMouseEnter?: PHPXEventHandler<MouseEvent>;
    onMouseLeave?: PHPXEventHandler<MouseEvent>;
    onMouseOver?: PHPXEventHandler<MouseEvent>;
    onMouseOut?: PHPXEventHandler<MouseEvent>;
    onMouseDown?: PHPXEventHandler<MouseEvent>;
    onMouseUp?: PHPXEventHandler<MouseEvent>;
    onMouseMove?: PHPXEventHandler<MouseEvent>;
    onContextMenu?: PHPXEventHandler<MouseEvent>;
    onAuxClick?: PHPXEventHandler<MouseEvent>;

    // Keyboard
    onKeyDown?: PHPXEventHandler<KeyboardEvent>;
    onKeyUp?: PHPXEventHandler<KeyboardEvent>;
    /** @deprecated Use onKeyDown instead */
    onKeyPress?: PHPXEventHandler<KeyboardEvent>;

    // Form / input
    onChange?: PHPXEventHandler<Event>;
    onInput?: PHPXEventHandler<InputEvent>;
    onBeforeInput?: PHPXEventHandler<InputEvent>;
    onSubmit?: PHPXEventHandler<SubmitEvent>;
    onReset?: PHPXEventHandler<Event>;
    onInvalid?: PHPXEventHandler<Event>;

    // Focus
    onFocus?: PHPXEventHandler<FocusEvent>;
    onBlur?: PHPXEventHandler<FocusEvent>;
    onFocusIn?: PHPXEventHandler<FocusEvent>;
    onFocusOut?: PHPXEventHandler<FocusEvent>;

    // Pointer
    onPointerDown?: PHPXEventHandler<PointerEvent>;
    onPointerUp?: PHPXEventHandler<PointerEvent>;
    onPointerMove?: PHPXEventHandler<PointerEvent>;
    onPointerEnter?: PHPXEventHandler<PointerEvent>;
    onPointerLeave?: PHPXEventHandler<PointerEvent>;
    onPointerOver?: PHPXEventHandler<PointerEvent>;
    onPointerOut?: PHPXEventHandler<PointerEvent>;
    onPointerCancel?: PHPXEventHandler<PointerEvent>;
    onGotPointerCapture?: PHPXEventHandler<PointerEvent>;
    onLostPointerCapture?: PHPXEventHandler<PointerEvent>;

    // Touch
    onTouchStart?: PHPXEventHandler<TouchEvent>;
    onTouchEnd?: PHPXEventHandler<TouchEvent>;
    onTouchMove?: PHPXEventHandler<TouchEvent>;
    onTouchCancel?: PHPXEventHandler<TouchEvent>;

    // Drag
    onDrag?: PHPXEventHandler<DragEvent>;
    onDragStart?: PHPXEventHandler<DragEvent>;
    onDragEnd?: PHPXEventHandler<DragEvent>;
    onDragOver?: PHPXEventHandler<DragEvent>;
    onDragEnter?: PHPXEventHandler<DragEvent>;
    onDragLeave?: PHPXEventHandler<DragEvent>;
    onDrop?: PHPXEventHandler<DragEvent>;

    // Scroll / wheel
    onScroll?: PHPXEventHandler<Event>;
    onScrollEnd?: PHPXEventHandler<Event>;
    onWheel?: PHPXEventHandler<WheelEvent>;

    // Clipboard
    onCopy?: PHPXEventHandler<ClipboardEvent>;
    onCut?: PHPXEventHandler<ClipboardEvent>;
    onPaste?: PHPXEventHandler<ClipboardEvent>;

    // Lifecycle / media
    onLoad?: PHPXEventHandler<Event>;
    onError?: PHPXEventHandler<ErrorEvent | Event>;
    onAbort?: PHPXEventHandler<Event>;

    // Animation / transition
    onAnimationStart?: PHPXEventHandler<AnimationEvent>;
    onAnimationEnd?: PHPXEventHandler<AnimationEvent>;
    onAnimationIteration?: PHPXEventHandler<AnimationEvent>;
    onTransitionStart?: PHPXEventHandler<TransitionEvent>;
    onTransitionEnd?: PHPXEventHandler<TransitionEvent>;
    onTransitionCancel?: PHPXEventHandler<TransitionEvent>;

    // Selection
    onSelect?: PHPXEventHandler<Event>;
    onSelectionChange?: PHPXEventHandler<Event>;

    // Toggle
    onToggle?: PHPXEventHandler<Event>;
    onBeforeToggle?: PHPXEventHandler<Event>;

    // Fullscreen / picture-in-picture
    onFullscreenChange?: PHPXEventHandler<Event>;
    onFullscreenError?: PHPXEventHandler<Event>;

    // Data wildcard attributes (data-*)
    [key: `data-${string}`]: string | number | boolean | undefined;
    // ARIA wildcard attributes (aria-*)
    [key: `aria-${string}`]: string | number | boolean | undefined;
}

// ─── Element-specific attribute interfaces ────────────────────────────────────

interface PHPXAnchorAttributes extends PHPXHTMLAttributes {
    href?: string;
    target?: '_blank' | '_self' | '_parent' | '_top' | (string & {});
    rel?: string;
    download?: string | boolean;
    /** Maps to `hreflang` */
    hrefLang?: string;
    type?: string;
    referrerPolicy?: ReferrerPolicy;
    ping?: string;
}

interface PHPXAreaAttributes extends PHPXHTMLAttributes {
    alt?: string;
    coords?: string;
    href?: string;
    shape?: 'rect' | 'circle' | 'poly' | 'default';
    target?: string;
    download?: string;
    rel?: string;
    referrerPolicy?: ReferrerPolicy;
}

interface PHPXAudioAttributes extends PHPXHTMLAttributes {
    src?: string;
    /** Maps to `autoplay` */
    autoPlay?: boolean;
    controls?: boolean;
    loop?: boolean;
    muted?: boolean;
    preload?: 'none' | 'metadata' | 'auto' | '';
    /** Maps to `crossorigin` */
    crossOrigin?: 'anonymous' | 'use-credentials' | '';
}

interface PHPXBaseAttributes extends PHPXHTMLAttributes {
    href?: string;
    target?: string;
}

interface PHPXBlockquoteAttributes extends PHPXHTMLAttributes {
    cite?: string;
}

interface PHPXButtonAttributes extends PHPXHTMLAttributes {
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    name?: string;
    value?: string;
    form?: string;
    /** Maps to `formaction` */
    formAction?: string;
    /** Maps to `formenctype` */
    formEncType?: string;
    /** Maps to `formmethod` */
    formMethod?: 'get' | 'post';
    /** Maps to `formnovalidate` */
    formNoValidate?: boolean;
    /** Maps to `formtarget` */
    formTarget?: string;
    /** Maps to `autofocus` */
    autoFocus?: boolean;
    popovertarget?: string;
    popovertargetaction?: 'hide' | 'show' | 'toggle';
}

interface PHPXCanvasAttributes extends PHPXHTMLAttributes {
    width?: number;
    height?: number;
}

interface PHPXColAttributes extends PHPXHTMLAttributes {
    span?: number;
}

interface PHPXDataAttributes extends PHPXHTMLAttributes {
    value?: string;
}

interface PHPXDelInsAttributes extends PHPXHTMLAttributes {
    cite?: string;
    /** Maps to `datetime` */
    dateTime?: string;
}

interface PHPXDetailsAttributes extends PHPXHTMLAttributes {
    open?: boolean;
    name?: string;
}

interface PHPXDialogAttributes extends PHPXHTMLAttributes {
    open?: boolean;
}

interface PHPXEmbedAttributes extends PHPXHTMLAttributes {
    src?: string;
    type?: string;
    width?: number | string;
    height?: number | string;
}

interface PHPXFieldsetAttributes extends PHPXHTMLAttributes {
    disabled?: boolean;
    form?: string;
    name?: string;
}

interface PHPXFormAttributes extends PHPXHTMLAttributes {
    action?: string;
    method?: 'get' | 'post' | 'dialog';
    /** Maps to `enctype` */
    encType?: 'application/x-www-form-urlencoded' | 'multipart/form-data' | 'text/plain';
    /** Maps to `novalidate` */
    noValidate?: boolean;
    /** Maps to `autocomplete` */
    autoComplete?: 'on' | 'off' | (string & {});
    target?: '_blank' | '_self' | '_parent' | '_top' | (string & {});
    name?: string;
    rel?: string;
    acceptCharset?: string;
}

interface PHPXHtmlAttributes extends PHPXHTMLAttributes {
    lang?: string;
    xmlns?: string;
}

interface PHPXIframeAttributes extends PHPXHTMLAttributes {
    src?: string;
    /** Maps to `srcdoc` */
    srcDoc?: string;
    name?: string;
    allow?: string;
    width?: number | string;
    height?: number | string;
    loading?: 'eager' | 'lazy';
    sandbox?: string;
    referrerPolicy?: ReferrerPolicy;
    /** Maps to `allowfullscreen` */
    allowFullScreen?: boolean;
}

interface PHPXImageAttributes extends PHPXHTMLAttributes {
    src?: string;
    alt?: string;
    width?: number | string;
    height?: number | string;
    loading?: 'eager' | 'lazy';
    decoding?: 'sync' | 'async' | 'auto';
    /** Maps to `crossorigin` */
    crossOrigin?: 'anonymous' | 'use-credentials' | '';
    referrerPolicy?: ReferrerPolicy;
    /** Maps to `srcset` */
    srcSet?: string;
    sizes?: string;
    /** Maps to `usemap` */
    useMap?: string;
    /** Maps to `ismap` */
    isMap?: boolean;
    fetchPriority?: 'high' | 'low' | 'auto';
}

interface PHPXInputAttributes extends PHPXHTMLAttributes {
    type?: 'text' | 'password' | 'email' | 'number' | 'tel' | 'url' | 'search'
         | 'date' | 'time' | 'datetime-local' | 'month' | 'week' | 'color'
         | 'range' | 'file' | 'checkbox' | 'radio' | 'submit' | 'reset'
         | 'button' | 'image' | 'hidden' | (string & {});
    value?: string | number;
    defaultValue?: string | number;
    checked?: boolean;
    defaultChecked?: boolean;
    disabled?: boolean;
    /** Maps to `readonly` */
    readOnly?: boolean;
    required?: boolean;
    placeholder?: string;
    name?: string;
    form?: string;
    /** Maps to `autofocus` */
    autoFocus?: boolean;
    /** Maps to `autocomplete` */
    autoComplete?: string;
    min?: string | number;
    max?: string | number;
    step?: string | number;
    /** Maps to `minlength` */
    minLength?: number;
    /** Maps to `maxlength` */
    maxLength?: number;
    pattern?: string;
    multiple?: boolean;
    accept?: string;
    capture?: 'user' | 'environment' | boolean;
    src?: string;
    alt?: string;
    width?: number | string;
    height?: number | string;
    list?: string;
    size?: number;
    /** Maps to `formaction` */
    formAction?: string;
    /** Maps to `formenctype` */
    formEncType?: string;
    /** Maps to `formmethod` */
    formMethod?: string;
    /** Maps to `formnovalidate` */
    formNoValidate?: boolean;
    /** Maps to `formtarget` */
    formTarget?: string;
    /** Maps to `inputmode` */
    inputMode?: 'none' | 'text' | 'decimal' | 'numeric' | 'tel' | 'search' | 'email' | 'url';
    popoverTargetAction?: 'hide' | 'show' | 'toggle';
    popoverTarget?: string;
    dirname?: string;
    /** Maps to `enterkeyhint` */
    enterKeyHint?: 'enter' | 'done' | 'go' | 'next' | 'previous' | 'search' | 'send';
}

interface PHPXLabelAttributes extends PHPXHTMLAttributes {
    /** Maps to the HTML `for` attribute */
    htmlFor?: string;
    form?: string;
}

interface PHPXLinkAttributes extends PHPXHTMLAttributes {
    href?: string;
    rel?: string;
    type?: string;
    media?: string;
    /** Maps to `crossorigin` */
    crossOrigin?: 'anonymous' | 'use-credentials' | '';
    referrerPolicy?: ReferrerPolicy;
    integrity?: string;
    /** Maps to `hreflang` */
    hrefLang?: string;
    sizes?: string;
    as?: string;
    disabled?: boolean;
    fetchPriority?: 'high' | 'low' | 'auto';
    blocking?: string;
    color?: string;
    imagesizes?: string;
    imagesrcset?: string;
}

interface PHPXMapAttributes extends PHPXHTMLAttributes {
    name?: string;
}

interface PHPXMetaAttributes extends PHPXHTMLAttributes {
    name?: string;
    content?: string;
    /** Maps to `http-equiv` */
    httpEquiv?: string;
    charset?: string;
    media?: string;
    property?: string;
}

interface PHPXMeterAttributes extends PHPXHTMLAttributes {
    value?: number;
    min?: number;
    max?: number;
    low?: number;
    high?: number;
    optimum?: number;
    form?: string;
}

interface PHPXObjectAttributes extends PHPXHTMLAttributes {
    data?: string;
    type?: string;
    name?: string;
    /** Maps to `usemap` */
    useMap?: string;
    width?: number | string;
    height?: number | string;
    form?: string;
}

interface PHPXOlAttributes extends PHPXHTMLAttributes {
    reversed?: boolean;
    start?: number;
    type?: '1' | 'a' | 'A' | 'i' | 'I';
}

interface PHPXOptgroupAttributes extends PHPXHTMLAttributes {
    label?: string;
    disabled?: boolean;
}

interface PHPXOptionAttributes extends PHPXHTMLAttributes {
    value?: string;
    selected?: boolean;
    disabled?: boolean;
    label?: string;
}

interface PHPXOutputAttributes extends PHPXHTMLAttributes {
    htmlFor?: string;
    form?: string;
    name?: string;
}

interface PHPXParamAttributes extends PHPXHTMLAttributes {
    name?: string;
    value?: string;
}

interface PHPXProgressAttributes extends PHPXHTMLAttributes {
    value?: number;
    max?: number;
}

interface PHPXScriptAttributes extends PHPXHTMLAttributes {
    src?: string;
    type?: string;
    async?: boolean;
    defer?: boolean;
    /** Maps to `crossorigin` */
    crossOrigin?: 'anonymous' | 'use-credentials' | '';
    integrity?: string;
    /** Maps to `nomodule` */
    noModule?: boolean;
    referrerPolicy?: ReferrerPolicy;
    fetchPriority?: 'high' | 'low' | 'auto';
    blocking?: string;
    nonce?: string;
}

interface PHPXSelectAttributes extends PHPXHTMLAttributes {
    value?: string | string[];
    defaultValue?: string | string[];
    disabled?: boolean;
    required?: boolean;
    multiple?: boolean;
    name?: string;
    form?: string;
    size?: number;
    /** Maps to `autofocus` */
    autoFocus?: boolean;
    /** Maps to `autocomplete` */
    autoComplete?: string;
}

interface PHPXSourceAttributes extends PHPXHTMLAttributes {
    src?: string;
    /** Maps to `srcset` */
    srcSet?: string;
    type?: string;
    media?: string;
    sizes?: string;
    width?: number;
    height?: number;
}

interface PHPXStyleAttributes extends PHPXHTMLAttributes {
    type?: string;
    media?: string;
    nonce?: string;
    /** @deprecated */
    scoped?: boolean;
    blocking?: string;
}

interface PHPXTableAttributes extends PHPXHTMLAttributes {
    summary?: string;
}

interface PHPXTableCellAttributes extends PHPXHTMLAttributes {
    /** Maps to `colspan` */
    colSpan?: number;
    /** Maps to `rowspan` */
    rowSpan?: number;
    headers?: string;
    scope?: 'col' | 'row' | 'colgroup' | 'rowgroup';
    abbr?: string;
}

interface PHPXTextareaAttributes extends PHPXHTMLAttributes {
    value?: string;
    defaultValue?: string;
    disabled?: boolean;
    /** Maps to `readonly` */
    readOnly?: boolean;
    required?: boolean;
    placeholder?: string;
    name?: string;
    form?: string;
    rows?: number;
    cols?: number;
    /** Maps to `autofocus` */
    autoFocus?: boolean;
    /** Maps to `autocomplete` */
    autoComplete?: string;
    /** Maps to `minlength` */
    minLength?: number;
    /** Maps to `maxlength` */
    maxLength?: number;
    wrap?: 'hard' | 'soft' | 'off';
    /** Maps to `inputmode` */
    inputMode?: string;
    /** Maps to `enterkeyhint` */
    enterKeyHint?: string;
    dirname?: string;
}

interface PHPXTimeAttributes extends PHPXHTMLAttributes {
    /** Maps to `datetime` */
    dateTime?: string;
}

interface PHPXTrackAttributes extends PHPXHTMLAttributes {
    src?: string;
    kind?: 'subtitles' | 'captions' | 'descriptions' | 'chapters' | 'metadata';
    srclang?: string;
    label?: string;
    default?: boolean;
}

interface PHPXVideoAttributes extends PHPXHTMLAttributes {
    src?: string;
    width?: number | string;
    height?: number | string;
    poster?: string;
    /** Maps to `autoplay` */
    autoPlay?: boolean;
    controls?: boolean;
    loop?: boolean;
    muted?: boolean;
    preload?: 'none' | 'metadata' | 'auto' | '';
    /** Maps to `crossorigin` */
    crossOrigin?: 'anonymous' | 'use-credentials' | '';
    /** Maps to `playsinline` */
    playsInline?: boolean;
    disablePictureInPicture?: boolean;
    controlsList?: string;
    disableRemotePlayback?: boolean;
}

// ─── SVG attribute interfaces ──────────────────────────────────────────────────

interface PHPXSvgAttributes extends PHPXHTMLAttributes {
    viewBox?: string;
    xmlns?: string;
    width?: number | string;
    height?: number | string;
    fill?: string;
    stroke?: string;
    strokeWidth?: number | string;
    strokeLinecap?: 'butt' | 'round' | 'square';
    strokeLinejoin?: 'miter' | 'round' | 'bevel';
    opacity?: number | string;
    transform?: string;
    preserveAspectRatio?: string;
}

interface PHPXSvgPathAttributes extends PHPXHTMLAttributes {
    d?: string;
    fill?: string;
    stroke?: string;
    strokeWidth?: number | string;
    opacity?: number | string;
    transform?: string;
    fillRule?: 'nonzero' | 'evenodd';
    clipRule?: 'nonzero' | 'evenodd';
}

interface PHPXSvgShapeAttributes extends PHPXHTMLAttributes {
    fill?: string;
    stroke?: string;
    strokeWidth?: number | string;
    opacity?: number | string;
    transform?: string;
}

// ─── Complete intrinsic elements map ──────────────────────────────────────────

interface PHPXIntrinsicElements {
    // ── Document structure ──────────────────────────────────────────────────
    html: PHPXHtmlAttributes;
    head: PHPXHTMLAttributes;
    body: PHPXHTMLAttributes;
    title: PHPXHTMLAttributes;

    // ── Metadata ────────────────────────────────────────────────────────────
    base: PHPXBaseAttributes;
    link: PHPXLinkAttributes;
    meta: PHPXMetaAttributes;
    style: PHPXStyleAttributes;

    // ── Sectioning ──────────────────────────────────────────────────────────
    header: PHPXHTMLAttributes;
    footer: PHPXHTMLAttributes;
    main: PHPXHTMLAttributes;
    nav: PHPXHTMLAttributes;
    aside: PHPXHTMLAttributes;
    article: PHPXHTMLAttributes;
    section: PHPXHTMLAttributes;
    address: PHPXHTMLAttributes;
    hgroup: PHPXHTMLAttributes;
    search: PHPXHTMLAttributes;

    // ── Headings ────────────────────────────────────────────────────────────
    h1: PHPXHTMLAttributes;
    h2: PHPXHTMLAttributes;
    h3: PHPXHTMLAttributes;
    h4: PHPXHTMLAttributes;
    h5: PHPXHTMLAttributes;
    h6: PHPXHTMLAttributes;

    // ── Grouping content ────────────────────────────────────────────────────
    div: PHPXHTMLAttributes;
    p: PHPXHTMLAttributes;
    blockquote: PHPXBlockquoteAttributes;
    pre: PHPXHTMLAttributes;
    ol: PHPXOlAttributes;
    ul: PHPXHTMLAttributes;
    li: PHPXHTMLAttributes;
    dl: PHPXHTMLAttributes;
    dt: PHPXHTMLAttributes;
    dd: PHPXHTMLAttributes;
    figure: PHPXHTMLAttributes;
    figcaption: PHPXHTMLAttributes;
    hr: PHPXHTMLAttributes;
    menu: PHPXHTMLAttributes;

    // ── Text content ────────────────────────────────────────────────────────
    a: PHPXAnchorAttributes;
    abbr: PHPXHTMLAttributes;
    b: PHPXHTMLAttributes;
    bdi: PHPXHTMLAttributes;
    bdo: PHPXHTMLAttributes;
    br: PHPXHTMLAttributes;
    cite: PHPXHTMLAttributes;
    code: PHPXHTMLAttributes;
    data: PHPXDataAttributes;
    del: PHPXDelInsAttributes;
    dfn: PHPXHTMLAttributes;
    em: PHPXHTMLAttributes;
    i: PHPXHTMLAttributes;
    ins: PHPXDelInsAttributes;
    kbd: PHPXHTMLAttributes;
    mark: PHPXHTMLAttributes;
    q: PHPXBlockquoteAttributes;
    rp: PHPXHTMLAttributes;
    rt: PHPXHTMLAttributes;
    ruby: PHPXHTMLAttributes;
    s: PHPXHTMLAttributes;
    samp: PHPXHTMLAttributes;
    small: PHPXHTMLAttributes;
    span: PHPXHTMLAttributes;
    strong: PHPXHTMLAttributes;
    sub: PHPXHTMLAttributes;
    sup: PHPXHTMLAttributes;
    time: PHPXTimeAttributes;
    u: PHPXHTMLAttributes;
    var: PHPXHTMLAttributes;
    wbr: PHPXHTMLAttributes;

    // ── Embedded content ────────────────────────────────────────────────────
    img: PHPXImageAttributes;
    audio: PHPXAudioAttributes;
    video: PHPXVideoAttributes;
    track: PHPXTrackAttributes;
    picture: PHPXHTMLAttributes;
    source: PHPXSourceAttributes;
    canvas: PHPXCanvasAttributes;
    map: PHPXMapAttributes;
    area: PHPXAreaAttributes;
    iframe: PHPXIframeAttributes;
    embed: PHPXEmbedAttributes;
    object: PHPXObjectAttributes;
    param: PHPXParamAttributes;

    // ── Scripting ───────────────────────────────────────────────────────────
    script: PHPXScriptAttributes;
    noscript: PHPXHTMLAttributes;
    template: PHPXHTMLAttributes;
    slot: PHPXHTMLAttributes;

    // ── Tables ──────────────────────────────────────────────────────────────
    table: PHPXTableAttributes;
    caption: PHPXHTMLAttributes;
    colgroup: PHPXHTMLAttributes;
    col: PHPXColAttributes;
    thead: PHPXHTMLAttributes;
    tbody: PHPXHTMLAttributes;
    tfoot: PHPXHTMLAttributes;
    tr: PHPXHTMLAttributes;
    th: PHPXTableCellAttributes;
    td: PHPXTableCellAttributes;

    // ── Forms ───────────────────────────────────────────────────────────────
    form: PHPXFormAttributes;
    input: PHPXInputAttributes;
    textarea: PHPXTextareaAttributes;
    select: PHPXSelectAttributes;
    option: PHPXOptionAttributes;
    optgroup: PHPXOptgroupAttributes;
    button: PHPXButtonAttributes;
    label: PHPXLabelAttributes;
    fieldset: PHPXFieldsetAttributes;
    legend: PHPXHTMLAttributes;
    datalist: PHPXHTMLAttributes;
    output: PHPXOutputAttributes;
    progress: PHPXProgressAttributes;
    meter: PHPXMeterAttributes;

    // ── Interactive ─────────────────────────────────────────────────────────
    details: PHPXDetailsAttributes;
    summary: PHPXHTMLAttributes;
    dialog: PHPXDialogAttributes;

    // ── SVG (basic) ─────────────────────────────────────────────────────────
    svg: PHPXSvgAttributes;
    path: PHPXSvgPathAttributes;
    rect: PHPXSvgShapeAttributes & {
        x?: number | string; y?: number | string;
        width?: number | string; height?: number | string;
        rx?: number | string; ry?: number | string;
    };
    circle: PHPXSvgShapeAttributes & {
        cx?: number | string; cy?: number | string; r?: number | string;
    };
    ellipse: PHPXSvgShapeAttributes & {
        cx?: number | string; cy?: number | string;
        rx?: number | string; ry?: number | string;
    };
    line: PHPXSvgShapeAttributes & {
        x1?: number | string; y1?: number | string;
        x2?: number | string; y2?: number | string;
    };
    polyline: PHPXSvgShapeAttributes & { points?: string };
    polygon: PHPXSvgShapeAttributes & { points?: string };
    text: PHPXSvgShapeAttributes & {
        x?: number | string; y?: number | string;
        dx?: number | string; dy?: number | string;
        textAnchor?: 'start' | 'middle' | 'end';
        dominantBaseline?: string;
    };
    tspan: PHPXSvgShapeAttributes & {
        x?: number | string; y?: number | string;
        dx?: number | string; dy?: number | string;
    };
    use: PHPXHTMLAttributes & { href?: string; x?: number | string; y?: number | string };
    defs: PHPXHTMLAttributes;
    g: PHPXSvgShapeAttributes & { transform?: string };
    clipPath: PHPXHTMLAttributes & { clipPathUnits?: string };
    mask: PHPXHTMLAttributes;
    symbol: PHPXSvgAttributes;
    linearGradient: PHPXHTMLAttributes & {
        x1?: string; y1?: string; x2?: string; y2?: string;
        gradientUnits?: 'userSpaceOnUse' | 'objectBoundingBox';
        gradientTransform?: string;
    };
    radialGradient: PHPXHTMLAttributes & {
        cx?: string; cy?: string; r?: string; fx?: string; fy?: string;
        gradientUnits?: 'userSpaceOnUse' | 'objectBoundingBox';
    };
    stop: PHPXHTMLAttributes & { offset?: string | number; stopColor?: string; stopOpacity?: number | string };
    pattern: PHPXHTMLAttributes & {
        x?: string; y?: string; width?: string; height?: string;
        patternUnits?: string; patternTransform?: string;
    };
    image: PHPXHTMLAttributes & { href?: string; x?: string; y?: string; width?: string; height?: string };
    foreignObject: PHPXHTMLAttributes & { x?: string; y?: string; width?: string; height?: string };

    // ── Custom elements / web components — catch-all ─────────────────────────
    [tagName: string]: PHPXHTMLAttributes;
}
