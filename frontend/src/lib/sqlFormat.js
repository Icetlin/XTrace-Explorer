/**
 * Format SQL the way Symfony WebProfilerBundle's "formatted query" view
 * renders it — keywords on their own line, parameters substituted in place
 * of `?` placeholders, semicolon terminator. Inspired by the profiler
 * template at vendor/symfony/web-profiler-bundle/Resources/views/Profiler/
 * db.html.twig, but kept small and self-contained (no Twig dependency).
 *
 * Example:
 *   in:  "SELECT t0.id, t0.email FROM user t0 WHERE t0.id = ?",
 *        params: [9450162]
 *   out: "SELECT
 *           t0.id,
 *           t0.email
 *         FROM
 *           user t0
 *         WHERE
 *           t0.id = 9450162;"
 *
 * The output keeps it on one logical line per clause so a token-level SQL
 * highlighter (e.g. highlightSql) can still wrap keywords in <span>s —
 * just with newlines in the text content.
 */

/**
 * Substitute positional `?` placeholders with literal values from params.
 * Strings are single-quoted with proper doubling of embedded quotes;
 * numbers and booleans render as-is; null/undefined render as SQL NULL.
 */
export function substituteParams(sql, params) {
  if (!Array.isArray(params) || params.length === 0) return sql
  let i = 0
  return sql.replace(/\?/g, () => {
    const v = params[i++]
    if (v === null || v === undefined) return 'NULL'
    if (typeof v === 'number' && Number.isFinite(v)) return String(v)
    if (typeof v === 'boolean') return v ? 'TRUE' : 'FALSE'
    // string-like
    return `'${String(v).replace(/'/g, "''")}'`
  })
}

/**
 * Multi-line formatter — every top-level clause gets its own line, ON
 * sub-clauses of JOINs are indented further, AND/OR conditions break
 * onto their own line within WHERE.
 *
 * Whitespace inside parentheses is collapsed (Symfony does the same).
 * We deliberately don't reformat column lists — they're already
 * comma-separated and adding newlines per column would be overkill.
 */
export function formatSql(sql, params) {
  if (!sql) return ''
  let out = substituteParams(sql, params || [])
  // Collapse runs of spaces/tabs to a single space first so the keyword
  // inserts land at predictable offsets.
  out = out.replace(/[ \t]+/g, ' ').trim()

  // Insert line breaks before major keywords. Match is case-insensitive and
  // uses word boundaries so `SELECT` inside an identifier (e.g.
  // `user_selections`) is not touched.
  const breaks = [
    // Top-level clauses: own line, no indent on the keyword itself.
    [/\bSELECT\b/gi,          '\nSELECT '],
    [/\bFROM\b/gi,            '\nFROM\n  '],
    [/\bWHERE\b/gi,           '\nWHERE\n  '],
    [/\bGROUP\s+BY\b/gi,      '\nGROUP BY\n  '],
    [/\bORDER\s+BY\b/gi,      '\nORDER BY\n  '],
    [/\bHAVING\b/gi,          '\nHAVING\n  '],
    [/\bLIMIT\b/gi,           '\nLIMIT '],
    [/\bOFFSET\b/gi,          '\nOFFSET '],
    [/\bUNION(\s+ALL)?\b/gi,  '\nUNION$1\n'],
    // JOIN + ON: indent JOIN to align with FROM, ON deeper under it.
    [/\b(LEFT\s+JOIN|RIGHT\s+JOIN|INNER\s+JOIN|OUTER\s+JOIN|CROSS\s+JOIN|FULL\s+JOIN|JOIN)\b/gi,
                                '\n  $1 '],
    [/\bON\b/gi,              '\n    ON '],
    // AND/OR inside WHERE — break to a new line at the same indent.
    [/\bAND\b/gi,             '\n  AND '],
    [/\bOR\b/gi,              '\n  OR '],
  ]
  for (const [re, repl] of breaks) {
    out = out.replace(re, repl)
  }

  // After FROM, JOIN, etc., break the column lists onto separate lines only
  // for the FROM clause (Symfony renders it that way). Keep column lists on
  // one line otherwise — long SELECT lists are still readable.
  // (This matches the Symfony profiler's "formatted query" output.)
  out = out.replace(/(FROM\s+)([\s\S]+?)(\s*(?:\n\s+(?:LEFT|RIGHT|INNER|OUTER|CROSS|FULL|JOIN|WHERE|GROUP|ORDER|HAVING|LIMIT|UNION)|;|$))/i,
    (_, head, body, tail) => {
      // Skip if body is tiny (single-line FROM).
      if (body.length < 60) return head + body + tail
      const indented = body.trim().split(',').map(s => '  ' + s.trim()).join(',\n')
      return head + '\n' + indented + tail
    })

  // Trim leading newline (the `SELECT` insert puts one before it).
  out = out.replace(/^\n/, '').trim()

  // Symfony always ends runnable queries with a semicolon. Add one if missing.
  if (!out.endsWith(';')) out += ';'

  return out
}