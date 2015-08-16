XenForo-UserMergeSearchUpdate
======================

Updates the search index when a user is merged into another

Warning; 
- If indexing fails it is possible this addon will continuously re-trying to update the same content to the new user owner.
- Multiple Elastic Search nodes may also introduce weirdness where the write hasn't propagated in time for the next batch of searching for content to update.
