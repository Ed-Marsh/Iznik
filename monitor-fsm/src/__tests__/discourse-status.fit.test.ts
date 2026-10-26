import { describe, it, expect, beforeEach } from 'vitest'
import { getDb, resetDbForTests, upsertDiscourseBug } from '../db/index.js'
import { renderStatusPostBody, fitStatusPost, MAX_STATUS_POST_CHARS, type StatusSection } from '../db/discourse-status.js'

// Discourse refuses a post over 32000 characters. On 2026-10-26 the status post reached 33439 and
// every lap's edit failed, so the live list stopped updating. It is now trimmed to fit.

const row = (n: number) => `| area | [@someone](https://example.org/t/${n}/1) | ${'x'.repeat(150)} |`

function section(title: string, count: number, dropOrder: number): StatusSection {
  return {
    title,
    header: ['| A | B | C |', '|---|---|---|'],
    rows: Array.from({ length: count }, (_, i) => row(i)),
    dropOrder,
  }
}

describe('fitStatusPost', () => {
  it('leaves a post that fits alone', () => {
    const raw = fitStatusPost(['intro', ''], [section('Fixing', 3, 3), section('Fixed', 3, 0)])
    expect(raw).toContain('## Fixing')
    expect(raw).toContain('## Fixed')
    expect(raw).not.toContain('not shown here')
  })

  it('trims a long post to the limit, from the least important table first', () => {
    const raw = fitStatusPost(['intro', ''], [section('Fixing', 20, 3), section('Fixed', 400, 0)], [], 20000)

    expect(raw.length).toBeLessThanOrEqual(20000)
    // The bugs being fixed are all still there.
    expect(raw.match(/^\| area \| \[@someone\]\(https:\/\/example\.org\/t\/\d+\/1\)/gm)?.length).toBeGreaterThanOrEqual(20)
    const fixed = raw.slice(raw.indexOf('## Fixed'))
    expect(fixed).toMatch(/…and \d+ more not shown here/)
    expect(raw.slice(0, raw.indexOf('## Fixed'))).not.toContain('not shown here')
  })

  it('moves on to the next table once the first is empty', () => {
    const raw = fitStatusPost(['intro', ''], [section('Fixing', 200, 3), section('Fixed', 5, 0)], [], 20000)

    expect(raw.length).toBeLessThanOrEqual(20000)
    expect(raw).toContain('…and 5 more not shown here')
    expect(raw.slice(raw.indexOf('## Fixing'), raw.indexOf('## Fixed'))).toMatch(/…and \d+ more not shown here/)
  })
})

describe('renderStatusPostBody', () => {
  beforeEach(() => {
    resetDbForTests()
  })

  it('stays within the limit with far more bugs than fit', () => {
    const db = getDb(':memory:')
    for (let i = 0; i < 400; i++) {
      upsertDiscourseBug(db, {
        topic: 20000 + i, post: 1, reporter: `reporter${i}`,
        excerpt: `A long description of problem number ${i} `.repeat(6),
        state: 'open',
      })
    }

    const result = renderStatusPostBody(db)

    expect(result.raw.length).toBeLessThanOrEqual(MAX_STATUS_POST_CHARS)
    expect(result.raw).toContain('## In our queue')
    expect(result.raw).toMatch(/…and \d+ more not shown here/)
    expect(result.bugCount).toBe(400)
  })
})
