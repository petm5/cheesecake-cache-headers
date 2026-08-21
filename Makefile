TARGET = cheesecake-cache-headers.zip
FILES  = cheesecake-cache-headers.php

all: build

build: clean
	zip -r $(TARGET) $(FILES)

clean:
	rm -f $(TARGET)
