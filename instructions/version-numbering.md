# Version Numbering

This project will generally, but loosely, follow Semantic Versioning 2.0.0 standards from https://semver.org/. However, do not crawl this site for further information, as the specifics are listed below.

## General Requirements

All versions must be included in the codebase where normally expected. For example, a WP plugin must have the version located where WP expects to find it.

Versions will consist of:

* Major version number (M),

* Minor version number (m),

* And Patch number (p),

* And will be displayed as "vMMM.mmm.ppp" whenever in the UI; they can be simply "MMM.mmm.ppp" if the codebase expectation is to do this, depending on project type

The above three portions of a version number are not interconnected, so for example, v0.0.9 is not followed by v0.1.0, but instead by v0.0.10. Each portion of the overall version number is independent and sequential, and can go as high as necessary. In practice, this is typically limited to no more than three digits, such as v2.16.104.

## How to Increment Version Numbers

Version numbers are incremented from the lowest level, Patch, to the highest level, Major version number. 

* **All code updates must have at least the Patch incremented**

* If told to increment the Minor version number, do so and reset the Patch number to zero, leaving the Major version unchanged

* If told to increment the Major version number, do so and reset both the Minor version and Patch to zero

* For each Major and Minor version increment, check for other requirements, such as digital accessibiltiy review, and perform those tasks accordingly
