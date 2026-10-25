---
last_reviewed: 2026-10-25
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/MessageIllustrationsService.php
  - iznik-batch/app/Services/AiImageMatcherService.php
  - iznik-batch/app/Support/ItemMatchKey.php
  - iznik-batch/resources/js/embed.mjs
  - iznik-server-go/misc/illustration.go
---

# AI item images

A post with no photo gets a simple AI-drawn picture of its item. One picture serves every post
with the same item name, so a picture is generated only for a name we have not seen.

> **A picture is chosen by item name, never by the post's description.** The description is
> often about collection, not the item, and a picture fitted to one post would fit the next post
> with that name worse.
>
> **A name we have not seen may still reuse a picture.** Before generating, the batch looks for
> a picture whose name is a variant of this one, and keeps any match as an alias. About half the
> names that used to get a new picture now reuse one.
>
> **A moderator removing a borrowed picture removes the match, not the picture.** The alias is
> deleted and the picture gets no "Reject", because the picture was fine for its own name.

## Where the pieces are

| Step | Code |
|---|---|
| Item name from a subject: no `OFFER:`/`OFFERED:`/`REQUESTED:` prefix, no location, no "please" | `MessageIllustrationsService::extractItemName`, `misc.IllustrationItemName` |
| Picture for a post as it is written (compose) | `misc.GetIllustration`: exact name, then alias |
| Pictures for posts that have none | `MessageIllustrationsService`, every minute |
| Reuse a picture for a new name | `AiImageMatcherService::matchMany` |
| Moderator removes a picture | `recordAIDeletions` and `removeAIImageAlias` in `message/message.go` |

Job illustrations share `ai_images` but never reach a post: the matcher only uses pictures that a
post has used.

## How a new name finds a picture

In order, stopping at the first that answers:

1. **Alias.** `ai_image_aliases` holds names already matched.
2. **Same key.** `ItemMatchKey::key` reduces a name to what its picture shows: lower case, no
   punctuation, singular, and without quantities, colours, sizes, condition words or "bag of".
   "3 bags of cat litter", "Cat Litter" and "used cat litter" share a key.
3. **Nearest name by embedding.** Each library name is embedded (nomic-embed-text, 256 dimensions,
   `search_query:` prefix, in `ai_images_embeddings`). The nearest name is used when:
   - cosine is at least **0.85** and the two **head nouns agree**, or
   - cosine is at least **0.92**, whatever the wording.

The head noun is the last word before the first "for", "with", "and" and similar. It stops
"ladder for bunk beds" taking the bunk bed picture, and "paper shredder" taking "shredded paper".
Heads that are a letter or two apart agree, so "Freazer" finds "Freezer", but a short word gets no
slack, so "took box" does not find "books".

Anything else is generated as before. If the embedder fails, nothing matches by embedding and the
names are generated: a failure costs a picture, not a wrong one.

## Why these thresholds

The matcher was replayed against every post picture generated from 1 August to 25 October 2026
(9,457 names). Each name could only match pictures that existed before it. A sample of 440
matches across the score bands was then judged blind: would the reused picture be acceptable
on that post?

| Band | Judged acceptable | Wrong |
|---|---|---|
| Same key | 100% | 0 of 60 |
| Head agrees, cosine 0.92 and up | 98% | 0 of 60 |
| Head agrees, 0.88 to 0.92 | 95% | 0 of 60 |
| Head agrees, 0.85 to 0.88 | 88% | 1 of 60 |
| Head agrees, 0.82 to 0.85 | 80% | 2 of 60 |
| Any wording, 0.92 and up | 98% | 1 of 40 |
| Any wording, 0.88 to 0.92 | 93% | 1 of 40 |

The rest of the "acceptable" shortfall is "partial": the post lists several things and the
picture shows one of them. With the thresholds above, 48.9% of those 9,457 names would have
reused a picture, an estimated 96% of them acceptable and 0.4% wrong. Lowering the head
threshold to 0.82 reuses 57% at 94% acceptable.

The replay is not in the repository. Re-running it needs `ai_images` and post usage from
production, the names embedded through the sidecar, then the same replay against a library that
grows in date order.
