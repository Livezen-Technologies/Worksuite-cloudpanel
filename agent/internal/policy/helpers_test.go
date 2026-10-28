package policy

import "time"

func timeNowPlus() time.Time { return time.Now().Add(time.Hour) }
